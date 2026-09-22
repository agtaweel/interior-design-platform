<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /organizations/{organization} (PROJECT_CONTEXT.md Sprint 8 "Settings" — S22 scoped
 * down to just the organization-profile/branding fields that already exist as columns since
 * Sprint 1: name, legal_name, logo_url, phone, email, currency, timezone). Gated behind
 * Permissions::MANAGE_ORGANIZATION, seeded since Sprint 1 (every role except Owner has it false —
 * see RoleSeeder) but never used by any endpoint until now.
 *
 * `settings_json` is deliberately NOT accepted here — PROJECT_CONTEXT.md's scoped-down field
 * list is exactly the seven columns above; `settings_json` is the locked-decision "multi-branch
 * flexibility" escape hatch (see PROJECT_CONTEXT.md's locked decision #6), not a general
 * settings bag this endpoint should let any MANAGE_ORGANIZATION holder freely rewrite.
 */
class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_ORGANIZATION);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
