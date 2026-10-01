<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /client/deals/{proposal}/request-changes (BRD v4 "Client Marketplace"). Mirrors
 * RequestChangesPublicProposalRequest's `comment` requirement; no `name` field since the
 * approver's name comes from the authenticated ClientUser.
 */
class RequestChangesClientDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }
}
