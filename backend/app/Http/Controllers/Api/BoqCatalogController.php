<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBoqCatalogCategoryRequest;
use App\Http\Requests\StoreBoqCatalogItemRequest;
use App\Http\Requests\UpdateBoqCatalogCategoryRequest;
use App\Http\Requests\UpdateBoqCatalogItemRequest;
use App\Http\Resources\BoqCatalogItemResource;
use App\Models\BoqCatalogCategory;
use App\Models\BoqCatalogItem;
use App\Services\Boq\BoqCatalogTreeService;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BOQ Master Catalog + Standard Templates. Mounted TWICE in routes/api.php: once inside the
 * `tenant` group at /boq-catalog/*, once inside the `platform.owner` group at
 * /platform/boq-catalog/* — the SAME controller serves both, branching on
 * TenantContext::hasOrganization() (true only on the tenant mount, since `platform.owner` runs
 * INSTEAD OF `tenant` — see EnsurePlatformOwner's docblock) rather than duplicating every method.
 * This mirrors how the catalog's own nullable organization_id column already encodes
 * system-vs-org as a data attribute, not a separate resource.
 *
 * Reads merge the caller's own organization's custom catalog with the global/system catalog
 * (BoqCatalogTreeService::buildCatalogTree($orgId)); the platform mount passes null, which means
 * "system catalog only" — NOT "every organization's catalog unscoped" (see that service's
 * docblock for why it deliberately bypasses the ambient BelongsToOrganization scope rather than
 * relying on `platform.owner`'s "no tenant context = no restriction" default, which would
 * otherwise mix every org's private catalog into the platform owner's system-catalog screen).
 *
 * Writes always create new rows for the caller's own scope: a tenant-mount write always gets
 * organization_id = TenantContext::organizationId(); a platform-mount write always gets
 * organization_id = null. A tenant-mount write can only ever resolve (for update/deactivate) a
 * row it already owns (organization_id = current org) — a global/system row is never editable
 * through the tenant mount, even though reads can see it; see resolveCategoryForMutation()/
 * resolveItemForMutation().
 */
class BoqCatalogController extends Controller
{
    public function __construct(private readonly BoqCatalogTreeService $treeService) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->treeService->buildCatalogTree($this->scopeOrganizationId()),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        if (trim($term) === '') {
            return response()->json(['data' => []]);
        }

        $items = $this->treeService->search($term, $this->scopeOrganizationId());

        return response()->json(['data' => BoqCatalogItemResource::collection($items)->resolve()]);
    }

    public function storeCategory(StoreBoqCatalogCategoryRequest $request): JsonResponse
    {
        $category = BoqCatalogCategory::create([
            ...$request->validated(),
            'organization_id' => $this->scopeOrganizationId(),
        ]);

        return response()->json(['data' => $this->categoryPayload($category->fresh())], 201);
    }

    public function updateCategory(UpdateBoqCatalogCategoryRequest $request, string $category): JsonResponse
    {
        $categoryModel = $this->resolveCategoryForMutation($category);

        if (! $categoryModel) {
            return $this->notFound();
        }

        $categoryModel->update($request->validated());

        return response()->json(['data' => $this->categoryPayload($categoryModel->fresh())]);
    }

    public function storeItem(StoreBoqCatalogItemRequest $request, string $category): JsonResponse
    {
        $categoryModel = $this->resolveCategoryForMutation($category);

        if (! $categoryModel) {
            return $this->notFound();
        }

        $item = BoqCatalogItem::create([
            ...$request->validated(),
            'category_id' => $categoryModel->id,
            'organization_id' => $this->scopeOrganizationId(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => new BoqCatalogItemResource($item->fresh())], 201);
    }

    public function updateItem(UpdateBoqCatalogItemRequest $request, string $item): JsonResponse
    {
        $itemModel = $this->resolveItemForMutation($item);

        if (! $itemModel) {
            return $this->notFound();
        }

        $itemModel->update($request->validated());

        return response()->json(['data' => new BoqCatalogItemResource($itemModel->fresh())]);
    }

    public function deactivateItem(string $item): JsonResponse
    {
        $this->authorizeManageBoq();

        $itemModel = $this->resolveItemForMutation($item);

        if (! $itemModel) {
            return $this->notFound();
        }

        $itemModel->update(['is_active' => false]);

        return response()->json(['data' => new BoqCatalogItemResource($itemModel->fresh())]);
    }

    /**
     * `$user->can(Permissions::MANAGE_BOQ)` always evaluates false on the /platform mount (it
     * needs a tenant context `platform.owner` never sets up — see
     * BoqTemplateAdminController::authorizeManageBoq()'s docblock for the full reasoning), so a
     * genuine platform owner must also be accepted here.
     */
    private function authorizeManageBoq(): void
    {
        $user = auth()->user();

        if (! $user->can(Permissions::MANAGE_BOQ) && ! $user->is_platform_owner) {
            throw new AuthorizationException;
        }
    }

    /** null when called through /platform (no tenant context resolved), else the current org. */
    private function scopeOrganizationId(): ?int
    {
        $context = app(TenantContext::class);

        return $context->hasOrganization() ? $context->organizationId() : null;
    }

    /**
     * A tenant-mount caller may only mutate a row in their OWN organization's custom catalog —
     * never a global/system row, even though reads can see it (see class docblock). A
     * platform-mount caller may only mutate a global/system row.
     */
    private function resolveCategoryForMutation(string $id): ?BoqCatalogCategory
    {
        $organizationId = $this->scopeOrganizationId();

        return $organizationId === null
            ? BoqCatalogCategory::withoutGlobalScopes()->whereNull('organization_id')->find($id)
            : BoqCatalogCategory::withoutGlobalScopes()->where('organization_id', $organizationId)->find($id);
    }

    private function resolveItemForMutation(string $id): ?BoqCatalogItem
    {
        $organizationId = $this->scopeOrganizationId();

        return $organizationId === null
            ? BoqCatalogItem::withoutGlobalScopes()->whereNull('organization_id')->find($id)
            : BoqCatalogItem::withoutGlobalScopes()->where('organization_id', $organizationId)->find($id);
    }

    private function categoryPayload(BoqCatalogCategory $category): array
    {
        return [
            'id' => $category->id,
            'organization_id' => $category->organization_id,
            'is_system' => $category->organization_id === null,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'name_en' => $category->name_en,
            'name_ar' => $category->name_ar,
            'sort_order' => $category->sort_order,
            'is_active' => $category->is_active,
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
                'details' => (object) [],
            ],
        ], 404);
    }
}
