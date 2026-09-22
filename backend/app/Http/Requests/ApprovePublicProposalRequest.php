<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /public/proposals/{token}/approve. Public, unauthenticated (token-authenticated
 * instead, verified separately in the controller) — authorize() always returns true, matching
 * every other public-endpoint FormRequest's shape in spirit (there is no user() to check
 * against; the `public-links` rate limiter + the token/OTP checks inside the controller are
 * the actual gates here, not this class).
 */
class ApprovePublicProposalRequest extends FormRequest
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
