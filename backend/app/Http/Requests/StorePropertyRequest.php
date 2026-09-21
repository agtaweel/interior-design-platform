<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /clients/{client}/properties. client_id and organization_id both come from the route
 * and tenant context respectively — never trusted from the request body.
 */
class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_CLIENTS);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['apartment', 'villa', 'office', 'retail', 'other'])],
            'compound' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'area_m2' => ['nullable', 'numeric', 'min:0'],
            'bedrooms' => ['nullable', 'integer', 'min:0'],
            'bathrooms' => ['nullable', 'integer', 'min:0'],
            'metadata_json' => ['nullable', 'array'],
        ];
    }
}
