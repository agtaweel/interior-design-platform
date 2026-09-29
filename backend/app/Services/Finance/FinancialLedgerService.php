<?php

namespace App\Services\Finance;

use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Services\Boq\BoqMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BRD v3 §4/§6 "Strict Financial Ledger — Core Differentiator": the single place that both
 * WRITES financial_transactions rows (post/reverse) and READS them back into the aggregate
 * figures every surface in this app needs (obligation/outstanding/actual cost/gross profit).
 * Every other service that used to just set a number directly onto Payment.amount/
 * Contract.contract_value now ALSO calls post() here — see PaymentRecordingService,
 * ContractService, ChangeOrderApplyService, ExpenseRecordingService, PurchaseOrderController.
 *
 * `amount` sign convention (see the migration's docblock for the full rationale): positive
 * magnitude for every type except change_order_charge/adjustment, which carry their own natural
 * signed value. summary()'s formula below is written against exactly that convention and is
 * verified against the BRD's own "Golden Financial Test Case": contract 1,000,000 + approved
 * changes 150,000 - credit 25,000 - paid 600,000 = 525,000 outstanding.
 */
final class FinancialLedgerService
{
    /**
     * @param  array{organization_id: int, project_id: int, scope: string, type: string, amount: string, transaction_date: string, created_by: int, source_entity_type?: ?string, source_entity_id?: ?int, source_document_id?: ?int, notes?: ?string, metadata?: ?array, currency?: string}  $attrs
     */
    public function post(array $attrs): FinancialTransaction
    {
        return FinancialTransaction::create(array_merge([
            'currency' => 'EGP',
            'status' => FinancialTransaction::STATUS_POSTED,
            'posted_by' => $attrs['created_by'],
            'posted_at' => now(),
        ], $attrs));
    }

    /**
     * Convenience wrapper for posting directly from a source model (Contract, ChangeOrder,
     * Payment, ProjectExpense, PurchaseOrderItem, ...) — fills source_entity_type/
     * source_entity_id from the model's own class/id rather than making every call site spell
     * that out.
     *
     * @param  array{organization_id: int, project_id: int, scope: string, type: string, amount: string, transaction_date: string, created_by: int, source_document_id?: ?int, notes?: ?string, metadata?: ?array, currency?: string}  $attrs
     */
    public function postFor(Model $source, array $attrs): FinancialTransaction
    {
        return $this->post(array_merge($attrs, [
            'source_entity_type' => $source::class,
            'source_entity_id' => $source->getKey(),
        ]));
    }

    /**
     * Reverses a posted transaction: flips the ORIGINAL row's status to 'reversed' (excluding it
     * from every summary() sum, since sums only ever include status='posted' rows) and inserts a
     * brand-new mirror-image 'reversal' row carrying the same signed amount/scope/source —
     * purely a visible audit-trail marker, never itself summed into any formula bucket. The
     * original's amount/date/source columns are never touched, per BRD's immutability rule.
     */
    public function reverse(FinancialTransaction $original, int $actorId, string $reason): FinancialTransaction
    {
        if ($original->status !== FinancialTransaction::STATUS_POSTED) {
            throw new RuntimeException('Only a posted transaction can be reversed.');
        }

        return DB::transaction(function () use ($original, $actorId, $reason) {
            $original->forceFill(['status' => FinancialTransaction::STATUS_REVERSED])->save();

            return FinancialTransaction::create([
                'organization_id' => $original->organization_id,
                'project_id' => $original->project_id,
                'scope' => $original->scope,
                'type' => FinancialTransaction::TYPE_REVERSAL,
                'amount' => $original->amount,
                'currency' => $original->currency,
                'transaction_date' => now()->toDateString(),
                'status' => FinancialTransaction::STATUS_POSTED,
                'source_entity_type' => $original->source_entity_type,
                'source_entity_id' => $original->source_entity_id,
                'source_document_id' => $original->source_document_id,
                'created_by' => $actorId,
                'posted_by' => $actorId,
                'posted_at' => now(),
                'reversal_of_id' => $original->id,
                'notes' => $reason,
            ]);
        });
    }

    /**
     * The one formula every financial figure in this app derives from (BRD §27 "one source of
     * truth"). Only status='posted' rows ever contribute — a 'reversed' row is excluded outright
     * and its mirror 'reversal' row is never summed into any bucket below (it exists purely as a
     * visible audit trail, see reverse()'s docblock).
     *
     *   obligation  = Σcontract_charge + Σchange_order_charge - Σcredit + Σadjustment(client)
     *   paid        = Σclient_payment - Σrefund
     *   outstanding = max(obligation - paid, 0)
     *   actual_cost = Σsupplier_invoice + Σexpense + Σcontractor_cost + Σadjustment(cost)
     *   gross_profit = obligation - actual_cost, or null if no contract_charge has ever posted
     *   margin_percent = gross_profit / obligation * 100 (2dp), or null if obligation is 0
     *
     * change_order_charge/adjustment carry their own natural sign (see class docblock), so they
     * are added via bcadd() directly rather than treated as a positive magnitude to subtract.
     *
     * Money fields follow BoqMoney::zeroAsInt()'s established convention (bare int 0 when
     * genuinely zero, a full-precision decimal string otherwise) — the exact same contract
     * ProjectFinancialsCalculator/ProjectCostCalculator already pin in existing tests.
     *
     * @return array{obligation: string|int, paid: string|int, outstanding: string|int, actual_cost: string|int, gross_profit: string|int|null, margin_percent: string|null}
     */
    public function summary(Project $project): array
    {
        return $this->summarize(
            FinancialTransaction::query()
                ->where('project_id', $project->id)
                ->where('status', FinancialTransaction::STATUS_POSTED)
                ->get(['type', 'scope', 'amount']),
        );
    }

    /**
     * BRD v3 §5/§20 "Platform Owner / Super Admin": the SAME formula as summary() above, applied
     * across every posted transaction platform-wide instead of one project's. obligation/paid/
     * actual_cost are straight linear sums, so these are identical whether computed per-project
     * and added up, or over every row at once. `outstanding`'s max(...,0) clamp is NOT linear
     * across projects (an overpaid project's negative balance would otherwise offset another
     * project's real receivable if summed independently) — computed here at the platform level
     * directly, this is deliberately the platform's actual net receivable position, not a naive
     * sum of independently-clamped per-project outstanding figures.
     *
     * @return array{obligation: string|int, paid: string|int, outstanding: string|int, actual_cost: string|int, gross_profit: string|int|null, margin_percent: string|null}
     */
    public function platformSummary(): array
    {
        return $this->summarize(
            FinancialTransaction::query()
                ->where('status', FinancialTransaction::STATUS_POSTED)
                ->get(['type', 'scope', 'amount']),
        );
    }

    /**
     * BRD v3 §5/§20 "Platform Owner / Super Admin": the org-detail drill-down variant of
     * platformSummary() — same shared formula, scoped to one organization's rows instead of
     * every row or one project's. Used by PlatformAnalyticsController::organization(), which
     * runs outside any tenant context (see EnsurePlatformOwner), so this filters by
     * organization_id explicitly rather than relying on OrganizationScope.
     *
     * @return array{obligation: string|int, paid: string|int, outstanding: string|int, actual_cost: string|int, gross_profit: string|int|null, margin_percent: string|null}
     */
    public function organizationSummary(int $organizationId): array
    {
        return $this->summarize(
            FinancialTransaction::query()
                ->where('organization_id', $organizationId)
                ->where('status', FinancialTransaction::STATUS_POSTED)
                ->get(['type', 'scope', 'amount']),
        );
    }

    /**
     * @param  Collection<int, FinancialTransaction>  $rows
     * @return array{obligation: string|int, paid: string|int, outstanding: string|int, actual_cost: string|int, gross_profit: string|int|null, margin_percent: string|null}
     */
    private function summarize(Collection $rows): array
    {
        $sum = fn (string $type): string => BoqMoney::sumAccessor($rows->where('type', $type), 'amount');
        $sumClientAdjustment = BoqMoney::sumAccessor(
            $rows->where('type', FinancialTransaction::TYPE_ADJUSTMENT)->where('scope', FinancialTransaction::SCOPE_CLIENT),
            'amount',
        );
        $sumCostAdjustment = BoqMoney::sumAccessor(
            $rows->where('type', FinancialTransaction::TYPE_ADJUSTMENT)->where('scope', FinancialTransaction::SCOPE_COST),
            'amount',
        );

        $hasContractCharge = $rows->where('type', FinancialTransaction::TYPE_CONTRACT_CHARGE)->isNotEmpty();

        $obligation = BoqMoney::zero();
        $obligation = bcadd($obligation, $sum(FinancialTransaction::TYPE_CONTRACT_CHARGE), BoqMoney::SCALE);
        $obligation = bcadd($obligation, $sum(FinancialTransaction::TYPE_CHANGE_ORDER_CHARGE), BoqMoney::SCALE);
        $obligation = bcsub($obligation, $sum(FinancialTransaction::TYPE_CREDIT), BoqMoney::SCALE);
        $obligation = bcadd($obligation, $sumClientAdjustment, BoqMoney::SCALE);

        $paid = bcsub($sum(FinancialTransaction::TYPE_CLIENT_PAYMENT), $sum(FinancialTransaction::TYPE_REFUND), BoqMoney::SCALE);

        $outstanding = bcsub($obligation, $paid, BoqMoney::SCALE);
        if (bccomp($outstanding, BoqMoney::zero(), BoqMoney::SCALE) < 0) {
            $outstanding = BoqMoney::zero();
        }

        $actualCost = BoqMoney::zero();
        $actualCost = bcadd($actualCost, $sum(FinancialTransaction::TYPE_SUPPLIER_INVOICE), BoqMoney::SCALE);
        $actualCost = bcadd($actualCost, $sum(FinancialTransaction::TYPE_EXPENSE), BoqMoney::SCALE);
        $actualCost = bcadd($actualCost, $sum(FinancialTransaction::TYPE_CONTRACTOR_COST), BoqMoney::SCALE);
        $actualCost = bcadd($actualCost, $sumCostAdjustment, BoqMoney::SCALE);

        [$grossProfit, $marginPercent] = $this->profitAndMargin($hasContractCharge, $obligation, $actualCost);

        return [
            'obligation' => BoqMoney::zeroAsInt($obligation),
            'paid' => BoqMoney::zeroAsInt($paid),
            'outstanding' => BoqMoney::zeroAsInt($outstanding),
            'actual_cost' => BoqMoney::zeroAsInt($actualCost),
            'gross_profit' => BoqMoney::zeroAsInt($grossProfit),
            'margin_percent' => $marginPercent,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function profitAndMargin(bool $hasContractCharge, string $obligation, string $actualCost): array
    {
        if (! $hasContractCharge) {
            return [null, null];
        }

        $grossProfit = bcsub($obligation, $actualCost, BoqMoney::SCALE);

        if (bccomp($obligation, BoqMoney::zero(), BoqMoney::SCALE) === 0) {
            return [$grossProfit, null];
        }

        $marginPercent = bcmul(bcdiv($grossProfit, $obligation, 4), '100', 2);

        return [$grossProfit, $marginPercent];
    }
}
