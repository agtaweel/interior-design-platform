<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /client/deals/{proposal}/approve (BRD v4 "Client Marketplace"). Unlike
 * ApprovePublicProposalRequest, no `name` or `otp` field — the approver's name comes from the
 * authenticated ClientUser, and OTP is skipped entirely for this logged-in flow (see
 * config('fitout.marketplace_deal_requires_otp')'s docblock).
 */
class ApproveClientDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
