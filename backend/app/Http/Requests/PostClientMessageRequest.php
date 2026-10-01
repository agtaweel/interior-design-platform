<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /client/conversations/{conversation}/messages (BRD v4 "Client Marketplace"). Authorize is
 * always true — ownership of the conversation itself is checked in the controller, not here,
 * since it depends on the route-resolved {conversation} rather than anything in the request body.
 */
class PostClientMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
