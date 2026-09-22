<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /public/proposals/{token}/request-changes. Public, unauthenticated — see
 * ApprovePublicProposalRequest's docblock for why authorize() is unconditionally true. No OTP
 * field: PROJECT_CONTEXT.md is explicit that request-changes is lower-stakes than approval
 * (a comment, not a binding commercial action) and does not require OTP.
 */
class RequestChangesPublicProposalRequest extends FormRequest
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
