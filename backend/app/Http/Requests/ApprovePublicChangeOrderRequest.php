<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /public/change-orders/{token}/approve. Public, unauthenticated (token-authenticated
 * instead, verified separately in the controller) — identical shape to
 * ApprovePublicProposalRequest, see that class's docblock for why authorize() is unconditionally
 * true here.
 */
class ApprovePublicChangeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'otp' => ['required', 'string'],
        ];
    }
}
