<?php

namespace Tests\Feature\Payments;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJECT_CONTEXT.md Sprint 6 -> POST /contracts/{id}/payment-schedules
 * (PaymentScheduleService::resolveAmount()). Pins the percentage-vs-amount resolution rules
 * precisely, per the task's instruction to verify the "percentage takes precedence when both are
 * given" framing against the actual implementation rather than assume it:
 *
 *   - percentage only -> amount computed via bcmath (contract_value * percentage / 100), never
 *     float. Tested against a deliberately non-round contract_value/percentage pair chosen so
 *     naive float*round() arithmetic would disagree with bcmath's truncating bcdiv() at the 2nd
 *     decimal place (see the docblock on test_creating_with_percentage... below for the exact
 *     numbers and why they were chosen).
 *   - amount only -> stored as-is (normalized to 2dp), percentage left null.
 *   - neither -> 422 (StorePaymentScheduleRequest::withValidator()).
 *   - both -> percentage wins; the client-supplied amount is discarded entirely, confirmed by
 *     supplying a wildly different "amount" alongside a percentage and asserting the computed
 *     (not the supplied) value is what gets stored.
 */
class PaymentScheduleCreationTest extends TestCase
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

    /** Builds an org, a contract with a specific contract_value, and a full-access user. */
    private function setUpContract(string $contractValue): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->create(['organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'client_id' => $client->id]);
        $proposal = ProposalVersion::factory()->approved()->create(['project_id' => $project->id]);
        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'proposal_version_id' => $proposal->id,
            'contract_value' => $contractValue,
        ]);
        $user = $this->fullAccessUser($organization);

        return [$contract, $user];
    }

    /**
     * cv=18053.19, pct=3.17 was found by brute-force search specifically because bcmath's
     * documented formula (bcmul(cv, pct, 4) then bcdiv(product, 100, 2)) truncates at 2dp
     * rather than rounding, and disagrees with naive float rounding at that exact boundary:
     *
     *   product = 18053.19 * 3.17 = 57228.6123 (bcmul, scale 4)
     *   amount  = 57228.6123 / 100 = 572.286123 -> truncated to 572.28 (bcdiv, scale 2)
     *
     * A naive `round((float) $cv * (float) $pct / 100, 2)` implementation would instead produce
     * 572.29 (rounds the 3rd decimal "6" up), so asserting on the exact string "572.28" (and
     * explicitly NOT "572.29") pins the bcmath-truncation behavior and would catch a regression
     * to float arithmetic even though float error alone is usually too small to observe at this
     * magnitude.
     */
    public function test_creating_with_percentage_computes_amount_correctly_via_bcmath_against_a_non_round_contract_value(): void
    {
        [$contract, $user] = $this->setUpContract('18053.19');

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
                'name' => 'Deposit',
                'sequence_no' => 1,
                'due_date' => '2026-11-01',
                'percentage' => 3.17,
            ]);

        $response->assertStatus(201);
        $this->assertSame('572.28', $response->json('data.amount'));
        $this->assertNotSame('572.29', $response->json('data.amount'));
        $this->assertSame('3.17', $response->json('data.percentage'));

        $schedule = PaymentSchedule::first();
        $this->assertSame('572.28', (string) $schedule->amount);
        $this->assertSame('3.17', (string) $schedule->percentage);
    }

    public function test_creating_with_only_amount_stores_it_directly_with_percentage_null(): void
    {
        [$contract, $user] = $this->setUpContract('100000.00');

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
                'name' => 'Final Payment',
                'sequence_no' => 2,
                'due_date' => '2026-12-01',
                'amount' => 4500.50,
            ]);

        $response->assertStatus(201);
        $this->assertSame('4500.50', $response->json('data.amount'));
        $this->assertNull($response->json('data.percentage'));

        $schedule = PaymentSchedule::first();
        $this->assertSame('4500.50', (string) $schedule->amount);
        $this->assertNull($schedule->percentage);
    }

    public function test_creating_with_neither_percentage_nor_amount_returns_422(): void
    {
        [$contract, $user] = $this->setUpContract('100000.00');

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
                'name' => 'Deposit',
                'sequence_no' => 1,
                'due_date' => '2026-11-01',
            ]);

        $response->assertStatus(422);
        $this->assertSame('validation_failed', $response->json('error.code'));
        $this->assertDatabaseCount('payment_schedules', 0);
    }

    /**
     * Verifies the actual implemented precedence (PaymentScheduleService::resolveAmount()'s
     * documented choice) rather than assuming it: when BOTH percentage and amount are supplied,
     * percentage wins and the client-supplied amount is discarded entirely — not averaged, not
     * validated for consistency, not preferred. Uses the same cv/pct pair as the bcmath test
     * above (expected computed amount "572.28") paired with a wildly different supplied `amount`
     * (999999.99) so a wrong implementation (e.g. "amount wins if present", or "amount must
     * match") would be caught immediately by the assertion.
     */
    public function test_creating_with_both_percentage_and_amount_computes_from_percentage_and_discards_the_supplied_amount(): void
    {
        [$contract, $user] = $this->setUpContract('18053.19');

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
                'name' => 'Deposit',
                'sequence_no' => 1,
                'due_date' => '2026-11-01',
                'percentage' => 3.17,
                'amount' => 999999.99,
            ]);

        $response->assertStatus(201);
        $this->assertSame('572.28', $response->json('data.amount'));
        $this->assertNotSame('999999.99', $response->json('data.amount'));
        $this->assertSame('3.17', $response->json('data.percentage'));

        $schedule = PaymentSchedule::first();
        $this->assertSame('572.28', (string) $schedule->amount);
    }

    public function test_schedules_are_listed_ordered_by_sequence_no(): void
    {
        [$contract, $user] = $this->setUpContract('100000.00');
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
            'name' => 'Final Payment', 'sequence_no' => 3, 'due_date' => '2027-01-01', 'amount' => 1000,
        ])->assertStatus(201);
        $this->withHeaders($headers)->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
            'name' => 'Deposit', 'sequence_no' => 1, 'due_date' => '2026-11-01', 'amount' => 2000,
        ])->assertStatus(201);
        $this->withHeaders($headers)->postJson("/api/v1/contracts/{$contract->id}/payment-schedules", [
            'name' => 'Milestone', 'sequence_no' => 2, 'due_date' => '2026-12-01', 'amount' => 3000,
        ])->assertStatus(201);

        $index = $this->withHeaders($headers)
            ->getJson("/api/v1/contracts/{$contract->id}/payment-schedules")
            ->assertStatus(200);

        $this->assertSame(['Deposit', 'Milestone', 'Final Payment'], $index->json('data.*.name'));
        $this->assertSame([1, 2, 3], $index->json('data.*.sequence_no'));
    }
}
