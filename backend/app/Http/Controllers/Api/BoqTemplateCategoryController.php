<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBoqTemplateCategoryRequest;
use App\Http\Resources\BoqTemplateCategoryResource;
use App\Models\BoqTemplateCategory;
use App\Services\Boq\BoqTreeService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * GET/POST /boq-templates/categories — organization-level BOQ template management (the API
 * surface the "apply template to project" feature needs to be testable/usable; not a
 * PRD-mandated screen this sprint — see PROJECT_CONTEXT.md Sprint 2 "Templates"). GET requires
 * only an active membership (read), POST requires Permissions::MANAGE_BOQ (checked inside
 * StoreBoqTemplateCategoryRequest::authorize()).
 */
class BoqTemplateCategoryController extends Controller
{
    public function index(BoqTreeService $treeService): JsonResponse
    {
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
