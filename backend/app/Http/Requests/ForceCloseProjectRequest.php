<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /projects/{project}/closeout/force-close (BRD v3 §12). Gated behind
 * Permissions::MANAGE_FINANCIAL_CLOSEOUT (Owner/Admin only, not the general MANAGE_BOQ
 * financial-mutation tier) — see that permission's docblock. `reason` is mandatory per the
 * BRD's own explicit requirement, same "written reason required" posture as
 * ReversePaymentRequest.
 */
class ForceCloseProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_FINANCIAL_CLOSEOUT);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
