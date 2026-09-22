<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\PaymentSchedule;
use App\Services\Boq\BoqMoney;

/**
 * POST /contracts/{id}/payment-schedules (PROJECT_CONTEXT.md Sprint 6). The controller has
 * already validated (via StorePaymentScheduleRequest) that the request supplies `name`,
 * `sequence_no`, `due_date`, and at least one of `percentage`/`amount` — this class only
 * resolves which of the two determines the stored `amount` and does the bcmath.
 *
 * Reuses App\Services\Boq\BoqMoney for its SCALE/zero() constants purely as a shared bcmath
 * convention holder — it is not BOQ-specific despite the namespace, and duplicating a
 * two-constant helper class for Payments would be pure repetition for no benefit.
 *
 * Precedence rule (PROJECT_CONTEXT.md: "exactly one of percentage/amount should effectively
 * determine the stored amount"): if `percentage` is present (even alongside a client-supplied
 * `amount`), it always wins — `amount` is computed from it and any client-supplied `amount`
 * value is discarded. Only when `percentage` is absent does the client-supplied `amount` get
 * stored directly, with `percentage` left null. This is a deliberate choice (not explicitly
 * spelled out for the both-present case) because storing a client-supplied `amount` that
 * doesn't actually match `contract_value * percentage / 100` would silently make the two
 * columns inconsistent with each other on the same row.
 */
final class PaymentScheduleService
{
    public function create(Contract $contract, array $data): PaymentSchedule
    {
        [$amount, $percentage] = $this->resolveAmount($contract, $data);

        return PaymentSchedule::create([
            'contract_id' => $contract->id,
            'name' => $data['name'],
            'sequence_no' => $data['sequence_no'],
            'due_date' => $data['due_date'],
            'percentage' => $percentage,
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }

    /**
     * @return array{0: string, 1: ?string} [amount, percentage]
     */
    private function resolveAmount(Contract $contract, array $data): array
    {
        $hasPercentage = array_key_exists('percentage', $data) && $data['percentage'] !== null;

        if ($hasPercentage) {
            $percentage = (string) $data['percentage'];

            // contract_value * percentage / 100, via bcmath throughout (never float). Multiply
            // at an intermediate scale of 4 (matching PricingCalculator::computeAmount()'s
            // identical percentage-of-a-decimal pattern) before dividing by 100 and rounding to
            // money scale, so the divide is the only rounding point.
            $product = bcmul((string) $contract->contract_value, $percentage, 4);
            $amount = bcdiv($product, '100', BoqMoney::SCALE);

            return [$amount, $percentage];
        }

        // Only `amount` was supplied (StorePaymentScheduleRequest guarantees at least one of
        // the two is present) — store it as-is, normalized to money scale via bcadd against
        // zero (same normalization trick PricingCalculator uses for fixed_amount rules), with
        // percentage left null per the "only amount given" case.
        $amount = bcadd((string) $data['amount'], BoqMoney::zero(), BoqMoney::SCALE);

        return [$amount, null];
    }
}
