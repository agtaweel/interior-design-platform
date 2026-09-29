<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payments/{payment}/reverse (BRD v3 §4 "reversal, not silent edit, is the only
 * correction path"). Requires a written reason — same mandatory-reason posture BRD v3 §12
 * requires for a force-close, since a payment reversal is an equally consequential financial
 * correction, not a routine action.
 */
class ReversePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
