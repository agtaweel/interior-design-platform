<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBoqTemplateRequest;
use App\Http\Requests\StoreBoqTemplateVersionItemRequest;
use App\Http\Requests\UpdateBoqTemplateRequest;
use App\Http\Requests\UpdateBoqTemplateVersionItemRequest;
use App\Http\Resources\BoqTemplateItemResource;
use App\Http\Resources\BoqTemplateResource;
use App\Http\Resources\BoqTemplateVersionResource;
use App\Models\BoqCatalogItem;
use App\Models\BoqTemplate;
use App\Models\BoqTemplateItem;
use App\Models\BoqTemplateVersion;
use App\Services\Boq\BoqTemplateVersionService;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BOQ Master Catalog + Standard Templates — template header/version/item admin management.
 * Mounted twice in routes/api.php exactly like BoqCatalogController (tenant + platform.owner) —
 * see that class's docblock for the full reasoning; this controller reuses the identical
 * scopeOrganizationId()/resolve-for-mutation pattern.
 *
 * A version's items are only mutable while that version's status is 'draft' — enforced here
 * (not just documented), since a published version must stay a trustworthy, permanent record of
 * exactly what any project that applied it actually saw (BoqItem.source_template_version_id).
 */
class BoqTemplateAdminController extends Controller
{
    public function __construct(private readonly BoqTemplateVersionService $versionService) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->scopeOrganizationId();

        $query = BoqTemplate::withoutGlobalScopes()
            ->withCount(['versions', 'applications'])
            ->with('activeVersion');

        if ($organizationId === null) {
            $query->whereNull('organization_id');
        } else {
            $query->where(fn ($q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id'));
        }

        if ($type = $request->query('template_type')) {
            $query->where('template_type', $type);
        }

        $templates = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => BoqTemplateResource::collection($templates)->resolve()]);
    }

    public function show(string $template): JsonResponse
    {
        $templateModel = $this->resolveTemplateForRead($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $templateModel->loadCount(['versions', 'applications'])->load('activeVersion');

        return response()->json(['data' => new BoqTemplateResource($templateModel)]);
    }

    public function store(StoreBoqTemplateRequest $request): JsonResponse
    {
        $template = BoqTemplate::create([
            ...$request->validated(),
            'organization_id' => $this->scopeOrganizationId(),
            'is_system' => $this->scopeOrganizationId() === null,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => new BoqTemplateResource($template->fresh())], 201);
    }

    public function update(UpdateBoqTemplateRequest $request, string $template): JsonResponse
    {
        $templateModel = $this->resolveTemplateForMutation($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $templateModel->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return response()->json(['data' => new BoqTemplateResource($templateModel->fresh())]);
    }

    public function destroy(string $template): JsonResponse
    {
        $this->authorizeManageBoq();

        $templateModel = $this->resolveTemplateForMutation($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $templateModel->delete();

        return response()->json(status: 204);
    }

    /**
     * Creates a new template header (new code, same type/classification) with a single draft
     * version copied from the source template's active (or latest) version — a fast "start from
     * an existing template" path for the admin UI, per the product spec's "Duplicate template"
     * requirement.
     */
    public function duplicate(Request $request, string $template): JsonResponse
    {
        $this->authorizeManageBoq();

        $templateModel = $this->resolveTemplateForRead($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $sourceVersion = $templateModel->activeVersion ?? $templateModel->versions()->latest('version_number')->first();

        $copy = BoqTemplate::create([
            'organization_id' => $this->scopeOrganizationId(),
            'code' => $templateModel->code.'-copy-'.now()->timestamp,
            'name' => $templateModel->name.' (Copy)',
            'name_en' => $templateModel->name_en,
            'name_ar' => $templateModel->name_ar,
            'description' => $templateModel->description,
            'description_en' => $templateModel->description_en,
            'description_ar' => $templateModel->description_ar,
            'template_type' => $templateModel->template_type,
            'project_type' => $templateModel->project_type,
            'finishing_level' => $templateModel->finishing_level,
            'is_system' => $this->scopeOrganizationId() === null,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        if ($sourceVersion) {
            $this->versionService->createDraftVersion($copy, $sourceVersion, $request->user()->id);
        }

        return response()->json(['data' => new BoqTemplateResource($copy->fresh())], 201);
    }

    public function versions(string $template): JsonResponse
    {
        $templateModel = $this->resolveTemplateForRead($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $versions = $templateModel->versions()->withCount('items')->orderByDesc('version_number')->get();

        return response()->json(['data' => BoqTemplateVersionResource::collection($versions)->resolve()]);
    }

    public function storeVersion(Request $request, string $template): JsonResponse
    {
        $this->authorizeManageBoq();

        $templateModel = $this->resolveTemplateForMutation($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $copyFrom = null;
        if ($copyFromId = $request->input('copy_from_version_id')) {
            $copyFrom = $templateModel->versions()->find($copyFromId);
        }

        $version = $this->versionService->createDraftVersion($templateModel, $copyFrom, $request->user()->id);

        return response()->json(['data' => new BoqTemplateVersionResource($version)], 201);
    }

    public function showVersion(string $template, string $version): JsonResponse
    {
        $templateModel = $this->resolveTemplateForRead($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $versionModel = $templateModel->versions()->withCount('items')->with('items.catalogItem', 'items.defaultUnit')->find($version);

        if (! $versionModel) {
            return $this->notFound();
        }

        return response()->json(['data' => new BoqTemplateVersionResource($versionModel)]);
    }

    public function storeItem(StoreBoqTemplateVersionItemRequest $request, string $template, string $version): JsonResponse
    {
        $templateModel = $this->resolveTemplateForMutation($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $versionModel = $templateModel->versions()->find($version);

        if (! $versionModel) {
            return $this->notFound();
        }

        if ($versionModel->status !== BoqTemplateVersion::STATUS_DRAFT) {
            return $this->error(409, 'version_not_draft', 'Only a draft version can have items added.');
        }

        $data = $request->validated();
        $catalogItem = BoqCatalogItem::find($data['catalog_item_id']);

        $item = BoqTemplateItem::create([
            ...$data,
            'template_version_id' => $versionModel->id,
            // Defaults to the catalog item's own category when not explicitly overridden —
            // see BoqTemplateItem's migration docblock on why this is a snapshot, not a live FK.
            'category_id' => $data['category_id'] ?? $catalogItem?->category_id,
        ]);

        return response()->json(['data' => new BoqTemplateItemResource($item->fresh(['catalogItem', 'defaultUnit']))], 201);
    }

    public function updateItem(UpdateBoqTemplateVersionItemRequest $request, string $templateItem): JsonResponse
    {
        $itemModel = $this->resolveItemForMutation($templateItem);

        if (! $itemModel) {
            return $this->notFound();
        }

        $itemModel->update($request->validated());

        return response()->json(['data' => new BoqTemplateItemResource($itemModel->fresh(['catalogItem', 'defaultUnit']))]);
    }

    public function destroyItem(string $templateItem): JsonResponse
    {
        $this->authorizeManageBoq();

        $itemModel = $this->resolveItemForMutation($templateItem);

        if (! $itemModel) {
            return $this->notFound();
        }

        $itemModel->delete();

        return response()->json(status: 204);
    }

    public function publish(string $template, string $version): JsonResponse
    {
        $this->authorizeManageBoq();

        $templateModel = $this->resolveTemplateForMutation($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $versionModel = $templateModel->versions()->find($version);

        if (! $versionModel) {
            return $this->notFound();
        }

        $this->versionService->publish($versionModel);

        return response()->json(['data' => new BoqTemplateVersionResource($versionModel->fresh())]);
    }

    public function activate(string $template): JsonResponse
    {
        return $this->setActive($template, true);
    }

    public function deactivate(string $template): JsonResponse
    {
        return $this->setActive($template, false);
    }

    private function setActive(string $template, bool $active): JsonResponse
    {
        $this->authorizeManageBoq();

        $templateModel = $this->resolveTemplateForMutation($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        $templateModel->update(['is_active' => $active]);

        return response()->json(['data' => new BoqTemplateResource($templateModel->fresh())]);
    }

    public function usage(string $template): JsonResponse
    {
        $this->authorizeManageBoq();

        $templateModel = $this->resolveTemplateForRead($template);

        if (! $templateModel) {
            return $this->notFound();
        }

        return response()->json([
            'data' => [
                'applications_count' => $templateModel->applications()->count(),
                'recent_applications' => $templateModel->applications()
                    ->with('project:id,name,code')
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->map(fn ($application) => [
                        'id' => $application->id,
                        'project' => $application->project ? ['id' => $application->project->id, 'name' => $application->project->name] : null,
                        'item_count' => $application->item_count,
                        'created_at' => $application->created_at,
                    ]),
            ],
        ]);
    }

    /**
     * `$user->can(Permissions::MANAGE_BOQ)` always evaluates false on the /platform mount — it
     * resolves through User::currentOrganizationRole(), which needs a tenant context that
     * `platform.owner` middleware never sets up (only `tenant` does) — so every action-specific
     * Gate::authorize() call here must also accept a genuine platform owner, same as every
     * FormRequest's authorize() in this feature (see StoreBoqTemplateRequest's docblock).
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

    /** Reads may see the caller's own org's templates merged with every global/system template. */
    private function resolveTemplateForRead(string $id): ?BoqTemplate
    {
        $organizationId = $this->scopeOrganizationId();

        $query = BoqTemplate::withoutGlobalScopes();

        return $organizationId === null
            ? $query->whereNull('organization_id')->find($id)
            : $query->where(fn ($q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id'))->find($id);
    }

    /** Mutations are restricted to the caller's own scope — see BoqCatalogController's docblock. */
    private function resolveTemplateForMutation(string $id): ?BoqTemplate
    {
        $organizationId = $this->scopeOrganizationId();

        return $organizationId === null
            ? BoqTemplate::withoutGlobalScopes()->whereNull('organization_id')->find($id)
            : BoqTemplate::withoutGlobalScopes()->where('organization_id', $organizationId)->find($id);
    }

    /**
     * BoqTemplateItem has no organization_id of its own — scoped indirectly via
     * template_version_id -> template_id -> organization_id, same convention as
     * BoqCategory/BoqItem being scoped indirectly via project_id. Also enforces the
     * draft-only-mutable rule.
     */
    private function resolveItemForMutation(string $id): ?BoqTemplateItem
    {
        $itemModel = BoqTemplateItem::with('templateVersion.template')->find($id);

        if (! $itemModel || ! $itemModel->templateVersion) {
            return null;
        }

        if ($itemModel->templateVersion->status !== BoqTemplateVersion::STATUS_DRAFT) {
            return null;
        }

        $template = $this->resolveTemplateForMutation((string) $itemModel->templateVersion->template_id);

        return $template ? $itemModel : null;
    }

    private function notFound(): JsonResponse
    {
        return $this->error(404, 'not_found', 'The requested resource was not found.');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) [],
            ],
        ], $status);
    }
}
