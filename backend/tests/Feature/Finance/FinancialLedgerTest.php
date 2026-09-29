<?php

namespace Tests\Feature\Finance;

use App\Models\BoqCategory;
use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FinancialTransaction;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProposalVersion;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\FinancialLedgerService;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BRD v3 §4/§6 "Strict Financial Ledger". Covers the write side (post/reverse) directly against
 * FinancialLedgerService, the live wiring (Contract/ChangeOrder/Payment API calls actually post
 * ledger rows), and — the load-bearing case — the BRD's own explicit "Golden Financial Test
 * Case": contract 1,000,000 + approved changes 150,000 - credit 25,000 - paid 600,000 = 525,000
 * outstanding.
 */
class FinancialLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPermissions(Organization $organization, array $permissions): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => $permissions]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function fullAccessUser(Organization $organization): User
    {
        return $this->memberWithPermissions($organization, [
            Permissions::MANAGE_BOQ => true,
            Permissions::VIEW_FINANCIALS => true,
        ]);
    }

    private function projectIn(Organization $organization): Project
    {
        $client = Client::factory()->create(['organization_id' => $organization->id]);

        return Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
    }

    public function test_golden_financial_test_case_matches_the_brds_own_worked_example(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        // Contract 1,000,000 — via the real from-proposal endpoint, so ContractService posts
        // the contract_charge exactly as production traffic would.
        $proposal = ProposalVersion::factory()->approved()->create([
            'project_id' => $project->id,
            'grand_total' => '1000000.00',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);

        // Approved changes +150,000 — via the real change-order apply endpoint.
        $changeOrder = ChangeOrder::factory()->create([
            'project_id' => $project->id,
            'status' => 'approved',
            'sent_at' => now(),
            'approved_at' => now(),
        ]);
        ChangeOrderItem::factory()->create([
            'change_order_id' => $changeOrder->id,
            'action' => 'add',
            'boq_item_id' => null,
            'description' => 'Extra scope',
            'quantity' => '1.00',
            'unit' => 'unit',
            'old_unit_price' => null,
            'new_unit_price' => '150000.00',
            'line_delta' => '150000.00',
        ]);
        $changeOrder->forceFill(['price_delta' => '150000.00'])->save();
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/change-orders/{$changeOrder->id}/apply")
            ->assertStatus(200);

        // Credit -25,000 — no dedicated endpoint yet (BRD v3-2/v3-4 scope); posted directly
        // through the ledger service, exactly as a future manual-credit endpoint would.
        app(FinancialLedgerService::class)->post([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope' => FinancialTransaction::SCOPE_CLIENT,
            'type' => FinancialTransaction::TYPE_CREDIT,
            'amount' => '25000.00',
            'transaction_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        // Paid 600,000 — via the real payment-recording endpoint.
        $contract = Contract::query()->where('project_id', $project->id)->firstOrFail();
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'amount' => '600000.00',
            'percentage' => null,
            'status' => 'pending',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => '600000.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
            ])->assertStatus(201);

        $summary = app(FinancialLedgerService::class)->summary($project->fresh());

        $this->assertSame('1125000.00', $summary['obligation']); // 1,000,000 + 150,000 - 25,000
        $this->assertSame('600000.00', $summary['paid']);
        $this->assertSame('525000.00', $summary['outstanding']);
    }

    public function test_post_creates_a_posted_transaction_with_the_given_attributes(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = User::factory()->create();

        $transaction = app(FinancialLedgerService::class)->post([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope' => FinancialTransaction::SCOPE_COST,
            'type' => FinancialTransaction::TYPE_EXPENSE,
            'amount' => '500.00',
            'transaction_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->assertSame(FinancialTransaction::STATUS_POSTED, $transaction->status);
        $this->assertNotNull($transaction->posted_at);
        $this->assertSame($user->id, $transaction->posted_by);
    }

    public function test_reverse_flips_original_to_reversed_and_inserts_a_mirror_reversal_row(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = User::factory()->create();
        $ledger = app(FinancialLedgerService::class);

        $original = $ledger->post([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope' => FinancialTransaction::SCOPE_CLIENT,
            'type' => FinancialTransaction::TYPE_CLIENT_PAYMENT,
            'amount' => '1000.00',
            'transaction_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $reversal = $ledger->reverse($original, $user->id, 'Bounced cheque');

        $this->assertSame(FinancialTransaction::STATUS_REVERSED, $original->fresh()->status);
        $this->assertSame(FinancialTransaction::TYPE_REVERSAL, $reversal->type);
        $this->assertSame(0, bccomp('1000.00', (string) $reversal->amount, 2));
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('Bounced cheque', $reversal->notes);

        // The reversed original no longer contributes to the ledger's paid figure — only the
        // 'reversal' row exists as an audit trail, never summed itself (see summary()'s
        // docblock).
        $summary = $ledger->summary($project);
        $this->assertSame(0, $summary['paid']);
    }

    public function test_reverse_rejects_a_transaction_that_is_not_currently_posted(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = User::factory()->create();
        $ledger = app(FinancialLedgerService::class);

        $original = $ledger->post([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'scope' => FinancialTransaction::SCOPE_CLIENT,
            'type' => FinancialTransaction::TYPE_CLIENT_PAYMENT,
            'amount' => '1000.00',
            'transaction_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        $ledger->reverse($original, $user->id, 'first reversal');

        $this->expectException(\RuntimeException::class);
        $ledger->reverse($original->fresh(), $user->id, 'second reversal');
    }

    public function test_payment_reverse_endpoint_reverses_the_ledger_entry(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $proposal = ProposalVersion::factory()->approved()->create([
            'project_id' => $project->id,
            'grand_total' => '10000.00',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/contracts/from-proposal/{$proposal->id}")
            ->assertStatus(201);
        $contract = Contract::query()->where('project_id', $project->id)->firstOrFail();
        $schedule = PaymentSchedule::factory()->create([
            'contract_id' => $contract->id,
            'sequence_no' => 1,
            'amount' => '5000.00',
            'percentage' => null,
            'status' => 'pending',
        ]);
        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/payment-schedules/{$schedule->id}/payments", [
                'amount' => '5000.00',
                'payment_method' => 'bank_transfer',
                'paid_at' => now()->toDateTimeString(),
            ])->assertStatus(201);
        $payment = Payment::query()->where('payment_schedule_id', $schedule->id)->firstOrFail();

        $before = app(FinancialLedgerService::class)->summary($project->fresh());
        $this->assertSame('5000.00', $before['paid']);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/payments/{$payment->id}/reverse", ['reason' => 'Duplicate entry'])
            ->assertStatus(200);

        $after = app(FinancialLedgerService::class)->summary($project->fresh());
        $this->assertSame(0, $after['paid']);

        // Reversing the ledger entry never touches the Payment row itself.
        $this->assertNotNull($payment->fresh());
        $this->assertSame(0, bccomp('5000.00', (string) $payment->fresh()->amount, 2));
    }

    public function test_reversing_a_payment_with_no_posted_ledger_entry_returns_409(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);
        $payment = Payment::factory()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
        ]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/payments/{$payment->id}/reverse", ['reason' => 'n/a'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'payment_not_reversible');
    }

    public function test_purchase_order_receipt_posts_a_cost_scoped_supplier_invoice_transaction(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->memberWithPermissions($organization, [
            \App\Support\Authorization\Permissions::MANAGE_PROCUREMENT => true,
        ]);

        $order = PurchaseOrder::factory()->create(['project_id' => $project->id, 'status' => 'sent']);
        $item = $order->items()->create([
            'description' => 'Tiles',
            'unit' => 'm2',
            'quantity' => '10.000',
            'quoted_unit_price' => '80.00',
        ]);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/purchase-orders/{$order->id}/receive", [
                'items' => [[
                    'id' => $item->id,
                    'received_quantity' => '10.000',
                    'actual_unit_price' => '85.00',
                ]],
            ])->assertStatus(200);

        $this->assertDatabaseHas('financial_transactions', [
            'project_id' => $project->id,
            'type' => FinancialTransaction::TYPE_SUPPLIER_INVOICE,
            'scope' => FinancialTransaction::SCOPE_COST,
            'source_entity_type' => \App\Models\PurchaseOrderItem::class,
            'source_entity_id' => $item->id,
        ]);
        $transaction = FinancialTransaction::query()->where('source_entity_id', $item->id)->firstOrFail();
        $this->assertSame(0, bccomp('850.00', (string) $transaction->amount, 2));
    }

    public function test_expense_recording_posts_a_cost_scoped_expense_transaction(): void
    {
        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);
        $user = $this->fullAccessUser($organization);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/projects/{$project->id}/expenses", [
                'category' => 'materials',
                'description' => 'Paint',
                'amount' => '1200.00',
                'expense_date' => now()->toDateString(),
            ])->assertStatus(201);

        $this->assertDatabaseHas('financial_transactions', [
            'project_id' => $project->id,
            'type' => FinancialTransaction::TYPE_EXPENSE,
            'scope' => FinancialTransaction::SCOPE_COST,
        ]);
    }
}
