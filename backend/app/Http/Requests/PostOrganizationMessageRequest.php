<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /inquiries/{conversation}/messages (BRD v4 "Client Marketplace"). Gated behind
 * Permissions::MANAGE_CLIENTS — replying to a prospective client is the same tier of action as
 * creating/updating a Client record.
 */
class PostOrganizationMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_CLIENTS);
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
