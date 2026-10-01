<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /client/conversations (BRD v4 "Client Marketplace"). Authorize is always true: any
 * authenticated ClientUser may start a conversation with any marketplace-listed organization —
 * there's no permission system on the client side, only EnsureClientUser's "is this a
 * ClientUser at all" gate, already enforced by the route middleware.
 */
class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer'],
        ];
    }
}
