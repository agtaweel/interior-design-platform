<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePricingRuleRequest;
use App\Http\Requests\UpdatePricingRuleRequest;
use App\Http\Resources\PricingRuleResource;
use App\Models\PricingRule;
use App\Models\Project;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET/POST /projects/{project}/pricing/rules, PATCH/DELETE /pricing/rules/{rule}.
 *
 * PATCH/DELETE are deliberately NOT nested under /projects/{project} (see routes/api.php),
 * mirroring BoqItemController exactly: {rule} is resolved manually and its project re-derived
 * from it, never via implicit route-model binding (SubstituteBindings runs before the `tenant`
 * middleware — see BoqItemController's docblock for the full rationale). PricingRule itself
 * carries no organization_id (scoped indirectly via project_id -> projects.organization_id,
 * see model docblock), so resolveTenantScopedRule() re-resolves the owning project through the
 * OrganizationScope-guarded Project::find() before treating the row as belonging to this tenant.
 *
 * GET (index) requires Permissions::MANAGE_BOQ (PROJECT_CONTEXT.md Sprint 8 "Permissions
 * hardening" — this used to require only an active membership, but PricingRuleResource exposes
 * markup percentages/values, exactly the internal pricing configuration the Definition of Done
 * says site users must never see; hardened to match every mutation's gate below). Mutations
 * require Permissions::MANAGE_BOQ, checked inside the Store/Update FormRequests' authorize() or,
 * for destroy() (no request body to validate), via an explicit Gate::authorize() call matching
 * BoqItemController::destroy()'s exact pattern.
 *
 * Hard delete on destroy() is intentional here (unlike BoqItem's archive-only convention):
 * nothing references a pricing_rule yet — no proposal snapshot exists until Sprint 4 — so there
 * is no immutability/historical-reference concern to guard against.
 */
class PricingRuleController extends Controller
{
    public function index(string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $rules = PricingRule::query()
            ->where('project_id', $projectModel->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => PricingRuleResource::collection($rules),
        ]);
    }

    public function store(StorePricingRuleRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $rule = PricingRule::create([
            ...$request->validated(),
            'project_id' => $projectModel->id,
        ]);

        // fresh(): sort_order/active have database-level defaults that Eloquent doesn't
        // reflect on the in-memory instance when omitted from the request — same rationale as
        // BoqCategoryController::store()'s fresh() call.
        return response()->json([
            'data' => new PricingRuleResource($rule->fresh()),
        ], 201);
    }

    public function update(UpdatePricingRuleRequest $request, string $rule): JsonResponse
    {
        $ruleModel = $this->resolveTenantScopedRule($rule);

        if (! $ruleModel) {
            return $this->notFound();
        }

        $ruleModel->update($request->validated());

        return response()->json([
            'data' => new PricingRuleResource($ruleModel->fresh()),
        ]);
    }

    public function destroy(string $rule): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $ruleModel = $this->resolveTenantScopedRule($rule);

        if (! $ruleModel) {
            return $this->notFound();
        }

        $ruleModel->delete();

        return response()->json(status: 204);
    }

    /**
     * Fetches a PricingRule by id (un-scoped, since the model has no organization_id of its
     * own — see class docblock) and confirms it belongs to the current tenant by re-resolving
     * its project through the OrganizationScope-guarded Project::find(). Returns null if the
     * rule doesn't exist OR belongs to another organization — callers must treat both as 404.
     */
    private function resolveTenantScopedRule(string $rule): ?PricingRule
    {
        $ruleModel = PricingRule::find($rule);

        if (! $ruleModel) {
            return null;
        }

        if (! Project::find($ruleModel->project_id)) {
            return null;
        }

        return $ruleModel;
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
