<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /organizations/{organization}/profile (BRD v4 "Client Marketplace"). Gated behind
 * Permissions::MANAGE_ORGANIZATION — same permission UpdateOrganizationRequest uses for the
 * internal profile, since this is the same "who's allowed to change how this org presents
 * itself" concern, just the public-facing half of it.
 */
class UpdateOrganizationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_ORGANIZATION);
    }

    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'services_offered' => ['sometimes', 'nullable', 'array'],
            'services_offered.*' => ['string', 'max:100'],
            'service_area' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_marketplace_listed' => ['sometimes', 'boolean'],
        ];
    }
}
