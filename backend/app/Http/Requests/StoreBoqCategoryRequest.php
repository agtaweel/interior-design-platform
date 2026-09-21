<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/boq/categories. project_id is never accepted from the body — it
 * comes from the route. parent_id (if given) must reference a category already in THIS
 * project, scoped via {$project} resolved through Project::find() (OrganizationScope-guarded),
 * same pattern as AddProjectMemberRequest resolving organization_id from the route project. If
 * the route project doesn't resolve (wrong id, or another organization's project), $projectId
 * below is null and the parent_id exists-check simply never matches, failing validation with
 * 422 rather than the request reaching the controller — BoqCategoryController still performs
 * its own manual Project::find()+404 check as defense-in-depth, matching the rest of this
 * codebase's convention for nested-resource writes.
 */
class StoreBoqCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        $projectId = Project::find($this->route('project'))?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('boq_categories', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
