<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /contracts/{contract}/payment-schedules. contract_id is never accepted from the body
 * (comes from the route, same convention as StoreBoqItemRequest/StorePricingRuleRequest).
 * Mutation gated behind Permissions::MANAGE_BOQ per PROJECT_CONTEXT.md's explicit instruction
 * ("gate mutations behind manage_boq, consistent with the rest of the commercial workflow").
 *
 * `percentage`/`amount` are both individually optional at the field-rule level (either one, or
 * both, may be sent) but at least one is required overall — enforced via the withValidator()
 * closure below rather than a `required_without` pair, because `required_without` on both
 * fields would fire when EITHER is missing even if the other IS present; what's actually needed
 * is "fail only if BOTH are absent", which is one combined check, not two independent per-field
 * ones. See PaymentScheduleService for which of the two determines the stored `amount` when
 * both are given.
 */
class StorePaymentScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sequence_no' => ['required', 'integer', 'min:1'],
            'due_date' => ['required', 'date'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('percentage') && ! $this->filled('amount')) {
                $validator->errors()->add('amount', 'Either percentage or amount is required.');
            }
        });
    }
}
