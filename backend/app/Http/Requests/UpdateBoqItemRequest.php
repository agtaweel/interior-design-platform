<?php

namespace App\Http\Requests;

use App\Models\BoqItem;
use App\Models\Project;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /boq/items/{item}. Deliberately NOT nested under /projects/{project} (see
 * routes/api.php's BOQ block), so there is no route project param to trust — the item's
 * project must be resolved from the item itself.
 *
 * BoqItem carries no organization_id of its own (see model docblock — it's scoped indirectly
 * via project_id -> projects.organization_id), so a raw `BoqItem::find($this->route('item'))`
 * is NOT tenant-scoped by itself: it would happily return another organization's item. The
 * actual tenant/permission enforcement therefore happens by re-resolving that item's project
 * through `Project::find()`, which IS OrganizationScope-guarded — if the item belongs to
 * another organization, `$project` below resolves to null, `$projectId` is null, and the
 * category_id/room_id exists-checks (which are scoped to that project id) simply never match,
 * failing validation. BoqItemController::update() performs the authoritative manual lookup +
 * 404 (matching this class's docblock instruction: "resolve the item's project internally to
 * check tenant/permission, do NOT use implicit route-model binding") before touching the row;
 * this class's exists-scoping is defense-in-depth for the case where the PATCH body itself
 * tries to move the item into a category/room outside its own project.
 */
class UpdateBoqItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        $item = BoqItem::find($this->route('item'));
        $projectId = $item ? Project::find($item->project_id)?->id : null;

        return [
            'category_id' => [
                'sometimes',
                'integer',
                Rule::exists('boq_categories', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],
            'room_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('rooms', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'quantity' => ['sometimes', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'material_unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'labor_unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'other_unit_cost' => ['sometimes', 'numeric', 'min:0'],
            'client_unit_price' => ['sometimes', 'numeric', 'min:0'],
            'supplier_id' => ['sometimes', 'nullable', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
