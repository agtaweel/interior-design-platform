<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /organizations/{organization}/portfolio (BRD v4 "Client Marketplace"). Mirrors
 * StoreProjectMediaRequest's validation shape (same max size/mime allowlist); gated behind
 * Permissions::MANAGE_ORGANIZATION since this is the public-profile/branding concern, same
 * permission UpdateOrganizationProfileRequest uses.
 */
class StoreOrganizationPortfolioMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_ORGANIZATION);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp'],
        ];
    }
}
