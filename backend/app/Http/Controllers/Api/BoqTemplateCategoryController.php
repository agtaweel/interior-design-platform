<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBoqTemplateCategoryRequest;
use App\Http\Resources\BoqTemplateCategoryResource;
use App\Models\BoqTemplateCategory;
use App\Services\Boq\BoqTreeService;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET/POST /boq-templates/categories — organization-level BOQ template management (the API
 * surface the "apply template to project" feature needs to be testable/usable; not a
 * PRD-mandated screen this sprint — see PROJECT_CONTEXT.md Sprint 2 "Templates"). Both GET and
 * POST require Permissions::MANAGE_BOQ (POST checked inside
 * StoreBoqTemplateCategoryRequest::authorize(), GET via an explicit Gate::authorize() call —
 * same split as RoomController, since there's no FormRequest on a plain GET route).
 *
 * Sprint 8 correction: GET originally required only active membership, on the reasoning that a
 * template "carries no project-specific cost data." That was wrong — the response
 * (BoqTemplateItemResource) still includes material_unit_cost/labor_unit_cost/other_unit_cost,
 * which is exactly the internal cost data the Sprint 8 hardening pass exists to protect; it's
 * cost data scoped to the organization rather than a project, not cost-free. Found by QA's final
 * project-wide Definition of Done review, fixed here to close the gap for real.
 */
class BoqTemplateCategoryController extends Controller
{
    public function index(BoqTreeService $treeService): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $organizationId = app(TenantContext::class)->organizationId();

        return response()->json([
            'data' => $treeService->buildTemplateTree($organizationId),
        ]);
    }

    public function store(StoreBoqTemplateCategoryRequest $request): JsonResponse
    {
        $category = BoqTemplateCategory::create([
            ...$request->validated(),
            // Never trust a client-supplied organization_id — always the resolved tenant
            // (BelongsToOrganization's saving guard would also auto-fill this; set explicitly
            // per the codebase's tenant-safety convention — see ClientController::store()).
            'organization_id' => app(TenantContext::class)->organizationId(),
        ]);

        // fresh(): sort_order has a database-level default — same fresh() rationale as
        // BoqCategoryController::store().
        return response()->json([
            'data' => new BoqTemplateCategoryResource($category->fresh()),
        ], 201);
    }
}
