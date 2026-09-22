<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /public/change-orders/{token}/reject. Public, unauthenticated — see
 * ApprovePublicChangeOrderRequest's docblock for why authorize() is unconditionally true. No
 * OTP field: PROJECT_CONTEXT.md is explicit that reject mirrors proposals' request-changes
 * (lower stakes than approval, a comment not a binding commercial action) and does not require
 * OTP.
 */
class RejectPublicChangeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }
}
