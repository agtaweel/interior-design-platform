<?php

namespace App\Console\Commands;

use App\Models\ChangeOrder;
use App\Models\Contract;
use App\Models\FinancialTransaction;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\ProjectExpense;
use App\Models\PurchaseOrderItem;
use App\Services\Finance\FinancialLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan ledger:backfill` — BRD v3 §4/§27 "one source of truth" requires
 * FinancialLedgerService::summary() to be the ONLY place client_obligation/outstanding/
 * actual_cost/gross_profit are computed from. Every Contract/ChangeOrder/Payment/ProjectExpense/
 * PurchaseOrderItem created BEFORE this rearchitecture landed has no corresponding
 * financial_transactions row yet, so this command walks each of those five tables once and posts
 * the equivalent historical ledger entry for anything that doesn't already have one.
 *
 * Idempotent by design: every check below is "does a posted (or reversed — either state proves
 * the row was already backfilled) financial_transactions row already exist for this exact
 * source_entity_type/source_entity_id" before posting, so running this command twice (or running
 * it after the live wiring has already posted new rows going forward) never double-posts.
 *
 * Historical rows have no authenticated actor to attribute `created_by` to (this runs from a
 * console context, never a request) — the organization's earliest-created member is used as a
 * reasonable "system/historical" stand-in, cached per-organization to avoid re-querying it once
 * per row.
 *
 * Contract.contract_value already reflects every change order applied to it since signing, so a
 * naive contract_charge = contract_value would double-count any already-applied change orders
 * once their own change_order_charge is also posted below. The contract_charge posted here is
 * therefore contract_value MINUS the sum of that project's already-applied change orders'
 * price_delta — i.e. the value the contract actually had at the moment it was signed.
 */
class BackfillFinancialLedger extends Command
{
    protected $signature = 'ledger:backfill';

    protected $description = 'Backfill financial_transactions rows for Contracts/ChangeOrders/Payments/Expenses/PurchaseOrderItems created before the v3 ledger existed';

    /** @var array<int, int> organization_id => user_id */
    private array $actorCache = [];

    public function handle(FinancialLedgerService $ledger): int
    {
        DB::transaction(function () use ($ledger) {
            $this->backfillContracts($ledger);
            $this->backfillChangeOrders($ledger);
            $this->backfillPayments($ledger);
            $this->backfillExpenses($ledger);
            $this->backfillPurchaseOrderItems($ledger);
        });

        $this->info('Ledger backfill complete.');

        return self::SUCCESS;
    }

    private function backfillContracts(FinancialLedgerService $ledger): void
    {
        $contracts = Contract::query()->with('project')->get();
        $posted = 0;

        foreach ($contracts as $contract) {
            if ($this->alreadyPosted(Contract::class, $contract->id)) {
                continue;
            }

            $appliedDeltaSum = ChangeOrder::query()
                ->where('project_id', $contract->project_id)
                ->where('status', 'applied')
                ->get()
                ->reduce(fn (string $carry, ChangeOrder $co) => bcadd($carry, (string) $co->price_delta, 2), '0.00');

            $originalValue = bcsub((string) $contract->contract_value, $appliedDeltaSum, 2);

            $ledger->postFor($contract, [
                'organization_id' => $contract->project->organization_id,
                'project_id' => $contract->project_id,
                'scope' => FinancialTransaction::SCOPE_CLIENT,
                'type' => FinancialTransaction::TYPE_CONTRACT_CHARGE,
                'amount' => $originalValue,
                'transaction_date' => ($contract->signed_at ?? $contract->created_at)->toDateString(),
                'created_by' => $this->resolveActor($contract->project->organization_id),
                'notes' => 'Backfilled by ledger:backfill',
            ]);

            $posted++;
        }

        $this->line("Contracts backfilled: {$posted}");
    }

    private function backfillChangeOrders(FinancialLedgerService $ledger): void
    {
        $changeOrders = ChangeOrder::query()->where('status', 'applied')->with('project')->get();
        $posted = 0;

        foreach ($changeOrders as $changeOrder) {
            if ($this->alreadyPosted(ChangeOrder::class, $changeOrder->id)) {
                continue;
            }

            $ledger->postFor($changeOrder, [
                'organization_id' => $changeOrder->project->organization_id,
                'project_id' => $changeOrder->project_id,
                'scope' => FinancialTransaction::SCOPE_CLIENT,
                'type' => FinancialTransaction::TYPE_CHANGE_ORDER_CHARGE,
                'amount' => (string) $changeOrder->price_delta,
                'transaction_date' => ($changeOrder->applied_at ?? $changeOrder->created_at)->toDateString(),
                'created_by' => $this->resolveActor($changeOrder->project->organization_id),
                'notes' => 'Backfilled by ledger:backfill',
            ]);

            $posted++;
        }

        $this->line("Change orders backfilled: {$posted}");
    }

    private function backfillPayments(FinancialLedgerService $ledger): void
    {
        $payments = Payment::query()->with('project')->get();
        $posted = 0;

        foreach ($payments as $payment) {
            if ($this->alreadyPosted(Payment::class, $payment->id)) {
                continue;
            }

            $ledger->postFor($payment, [
                'organization_id' => $payment->project->organization_id,
                'project_id' => $payment->project_id,
                'scope' => FinancialTransaction::SCOPE_CLIENT,
                'type' => FinancialTransaction::TYPE_CLIENT_PAYMENT,
                'amount' => (string) $payment->amount,
                'transaction_date' => $payment->paid_at->toDateString(),
                'created_by' => $this->resolveActor($payment->project->organization_id),
                'notes' => 'Backfilled by ledger:backfill',
            ]);

            $posted++;
        }

        $this->line("Payments backfilled: {$posted}");
    }

    private function backfillExpenses(FinancialLedgerService $ledger): void
    {
        $expenses = ProjectExpense::query()->with('project')->get();
        $posted = 0;

        foreach ($expenses as $expense) {
            if ($this->alreadyPosted(ProjectExpense::class, $expense->id)) {
                continue;
            }

            $ledger->postFor($expense, [
                'organization_id' => $expense->project->organization_id,
                'project_id' => $expense->project_id,
                'scope' => FinancialTransaction::SCOPE_COST,
                'type' => FinancialTransaction::TYPE_EXPENSE,
                'amount' => (string) $expense->amount,
                'transaction_date' => $expense->expense_date->toDateString(),
                'created_by' => $this->resolveActor($expense->project->organization_id),
                'notes' => 'Backfilled by ledger:backfill',
            ]);

            $posted++;
        }

        $this->line("Expenses backfilled: {$posted}");
    }

    private function backfillPurchaseOrderItems(FinancialLedgerService $ledger): void
    {
        $items = PurchaseOrderItem::query()
            ->whereNotNull('actual_unit_price')
            ->with('purchaseOrder.project')
            ->get();
        $posted = 0;

        foreach ($items as $item) {
            if ($this->alreadyPosted(PurchaseOrderItem::class, $item->id)) {
                continue;
            }

            $project = $item->purchaseOrder->project;

            $ledger->postFor($item, [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'scope' => FinancialTransaction::SCOPE_COST,
                'type' => FinancialTransaction::TYPE_SUPPLIER_INVOICE,
                'amount' => $item->actualTotal(),
                'transaction_date' => $item->updated_at->toDateString(),
                'created_by' => $this->resolveActor($project->organization_id),
                'notes' => 'Backfilled by ledger:backfill',
            ]);

            $posted++;
        }

        $this->line("Purchase order items backfilled: {$posted}");
    }

    private function alreadyPosted(string $sourceEntityType, int $sourceEntityId): bool
    {
        return FinancialTransaction::query()
            ->where('source_entity_type', $sourceEntityType)
            ->where('source_entity_id', $sourceEntityId)
            ->exists();
    }

    private function resolveActor(int $organizationId): int
    {
        if (isset($this->actorCache[$organizationId])) {
            return $this->actorCache[$organizationId];
        }

        $userId = Organization::query()->findOrFail($organizationId)
            ->users()
            ->oldest('users.created_at')
            ->first()
            ?->id;

        if (! $userId) {
            $this->fail("Organization {$organizationId} has no members to attribute backfilled ledger rows to.");
        }

        return $this->actorCache[$organizationId] = $userId;
    }
}
