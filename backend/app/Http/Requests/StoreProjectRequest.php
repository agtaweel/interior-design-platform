<?php

namespace App\Http\Requests;

use App\Models\Property;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /projects. organization_id is never accepted from the body — ProjectController derives
 * it from the current tenant context. client_id/property_id/responsible_user_id are all
 * validated against the current organization via scoped `exists` rules built on the raw
 * TenantContext organization id (not the model's OrganizationScope), so a request can never
 * reference another organization's records even indirectly.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_PROJECTS);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'property_id' => [
                'nullable',
                'integer',
                Rule::exists('properties', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            // Optional: if omitted, ProjectController auto-generates a sequential code. If
            // provided, it must be unique within the current organization (matches the
            // projects table's (organization_id, code) unique constraint).
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('projects', 'code')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'on_hold', 'completed', 'cancelled'])],
            'start_date' => ['nullable', 'date'],
            'target_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'responsible_user_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_members', 'user_id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active')
                ),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $clientId = $this->input('client_id');
            $propertyId = $this->input('property_id');

            if (! $clientId || ! $propertyId) {
                return;
            }

            $property = Property::find($propertyId);

            if ($property && (int) $property->client_id !== (int) $clientId) {
                $validator->errors()->add('property_id', 'The selected property does not belong to the selected client.');
            }
        });
    }
}
