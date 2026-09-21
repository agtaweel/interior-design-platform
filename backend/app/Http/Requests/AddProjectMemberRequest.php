<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/members. Resolves the project's organization directly (rather than
 * from TenantContext) so the user_id exists-check is scoped to the right organization even
 * though ProjectMember doesn't carry organization_id itself — see the ProjectMember model
 * docblock. If the route's {project} doesn't resolve to a project in the current tenant (bad
 * id, or another organization's id), $organizationId below is null and the exists-check simply
 * never matches — the request fails validation (422) rather than crashing or leaking whether
 * that project id exists elsewhere.
 */
class AddProjectMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_PROJECTS);
    }

    public function rules(): array
    {
        $organizationId = Project::find($this->route('project'))?->organization_id;

        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('organization_members', 'user_id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active')
                ),
            ],
            'role' => ['required', 'string', 'max:100'],
        ];
    }
}
