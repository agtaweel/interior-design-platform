<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/services. project_id is never accepted from the body — it comes
 * from the route, resolved (and tenant-scoped) by ProjectServiceController.
 */
class AddProjectServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_PROJECTS);
    }

    public function rules(): array
    {
        return [
            'service_type' => ['required', 'string', 'max:100'],
            'pricing_method' => ['required', Rule::in(['fixed', 'per_m2', 'per_room', 'percentage'])],
            'price' => ['required', 'numeric', 'min:0'],
            'metadata_json' => ['nullable', 'array'],
        ];
    }
}
