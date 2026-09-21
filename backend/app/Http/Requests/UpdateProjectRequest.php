<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Property;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /projects/{project}. client_id is intentionally NOT updatable here — reassigning a
 * project to a different client is out of Sprint 1 scope. property_id may change but must
 * still belong to the project's existing client.
 */
class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_PROJECTS);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'on_hold', 'completed', 'cancelled'])],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'target_end_date' => ['sometimes', 'nullable', 'date'],
            'property_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('properties', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'responsible_user_id' => [
                'sometimes',
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
            if (! $this->filled('property_id')) {
                return;
            }

            $project = Project::find($this->route('project'));
            $property = Property::find($this->input('property_id'));

            if ($project && $property && (int) $property->client_id !== (int) $project->client_id) {
                $validator->errors()->add('property_id', "The selected property does not belong to this project's client.");
            }
        });
    }
}
