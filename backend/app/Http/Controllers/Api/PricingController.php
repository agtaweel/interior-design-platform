<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Pricing\PricingCalculator;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * POST /projects/{project}/pricing/recalculate, GET /projects/{project}/pricing/breakdown.
 * {project} follows the same manual-lookup convention as every other project-nested controller
 * (BoqController, RoomController) — see BoqItemController's docblock for why implicit
 * route-model binding is unsafe here.
 *
 * recalculate() is a commercial mutation (it changes the cached figures that will eventually
 * drive ProjectResource.financials.value and, from Sprint 4 on, what a client is shown as their
 * project's price) so it requires Permissions::MANAGE_BOQ, the same permission BOQ writes use —
 * pricing is part of the same designer/estimator workflow (see StorePricingRuleRequest's
 * docblock for the full rationale) — and runs inside a DB transaction per PROJECT_CONTEXT.md's
 * NFR that commercial recalculations are transactional. No FormRequest exists for it (the POST
 * body is empty — nothing to validate), so the permission check is an explicit Gate::authorize()
 * call, matching BoqItemController::destroy()'s exact pattern for body-less mutations.
 *
 * breakdown() is a read, so it requires only an active membership (`tenant` middleware), no
 * extra permission — matching GET /projects/{project}/boq's posture.
 *
 * ## breakdown()'s live-vs-persisted design decision (documented per this sprint's "your
 * judgment" instruction)
 *
 * The requested response shape (an ordered per-rule list with base_amount_used/computed_amount/
 * running_subtotal_after for EACH rule) cannot be reconstructed from the cache columns alone —
 * `projects` only stores the final six aggregate totals, not a per-rule snapshot, and
 * db-architect's schema deliberately has no such snapshot table (that level of historical
 * immutability doesn't exist until Sprint 4's proposal snapshots). So breakdown() re-runs the
 * exact same PricingCalculator algorithm recalculate() uses, live, against the project's
 * CURRENT boq_items and CURRENT active pricing_rules — it never persists anything itself.
 *
 * The one guard this sprint's spec explicitly asked for: if `priced_at` is still null (this
 * project has never been recalculated even once), breakdown() does NOT run that live
 * computation at all — it returns an explicit "not yet priced" shape (`priced: false`, every
 * total null, empty rule list). This keeps the endpoint from ever showing a plausible-looking
 * grand_total that was never actually saved anywhere while ProjectResource.financials.value
 * (which reads the persisted, still-null cache column) shows 0 — a confusing mismatch the sprint
 * scope specifically called out to avoid.
 *
 * Once a project HAS been recalculated at least once, breakdown() intentionally shows a live
 * "what would recalculating produce right now" preview (using the persisted `priced_at`
 * timestamp of the last actual save, not `now()`) rather than replaying a frozen snapshot —
 * this matches the PRD's "show formulas clearly" requirement for the internal Pricing Panel
 * and means adding/editing/deactivating a rule is immediately visible here without forcing a
 * recalculate first. The tradeoff: if rules or BOQ items changed since the last recalculate,
 * the totals shown here can differ from the persisted cache columns (and thus from
 * ProjectResource.financials.value) until POST /pricing/recalculate is called again — that
 * divergence is expected and is exactly what should prompt a designer to hit "Recalculate".
 */
class PricingController extends Controller
{
    public function __construct(private readonly PricingCalculator $calculator) {}

    public function recalculate(string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $result = DB::transaction(function () use ($projectModel) {
            $result = $this->calculator->calculate($projectModel);

            // forceFill(), not update()/fill(): Project's cache columns are deliberately NOT
            // in the model's #[Fillable] attribute (see Project's cast() docblock) precisely
            // so a general client-supplied update (PATCH /projects/{id}) can never write them
            // — but that same guarding also silently drops a plain ->update() call from HERE,
            // the one legitimate writer. forceFill() bypasses mass-assignment protection for
            // this intentional internal write while leaving the fillable guard fully intact
            // for every other caller.
            $projectModel->forceFill([
                'direct_cost_total' => $result['direct_cost_total'],
                'client_subtotal' => $result['client_subtotal'],
                'markup_total' => $result['markup_total'],
                'fees_total' => $result['fees_total'],
                'discount_total' => $result['discount_total'],
                'grand_total' => $result['grand_total'],
                'priced_at' => now(),
            ])->save();

            return $result;
        });

        return response()->json([
            'data' => [
                'priced' => true,
                'direct_cost_total' => $result['direct_cost_total'],
                'client_subtotal' => $result['client_subtotal'],
                'rules' => $result['rules'],
                'markup_total' => $result['markup_total'],
                'fees_total' => $result['fees_total'],
                'discount_total' => $result['discount_total'],
                'grand_total' => $result['grand_total'],
                'priced_at' => $projectModel->fresh()->priced_at,
            ],
        ]);
    }

    public function breakdown(string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        if ($projectModel->priced_at === null) {
            return response()->json([
                'data' => [
                    'priced' => false,
                    'direct_cost_total' => null,
                    'client_subtotal' => null,
                    'rules' => [],
                    'markup_total' => null,
                    'fees_total' => null,
                    'discount_total' => null,
                    'grand_total' => null,
                    'priced_at' => null,
                ],
            ]);
        }

        return response()->json([
            'data' => $this->livePayload($projectModel),
        ]);
    }

    /**
     * breakdown()'s live-preview payload once a project has been priced at least once (see class
     * docblock). recalculate() builds its own response directly from the result it just
     * persisted inside the transaction above, rather than calling this and recomputing a second
     * time. Reads `priced_at` off the given model rather than `now()`, so breakdown() always
     * reports the timestamp of the last actual save, not the moment of this read.
     */
    private function livePayload(Project $project): array
    {
        $result = $this->calculator->calculate($project);

        return [
            'priced' => true,
            'direct_cost_total' => $result['direct_cost_total'],
            'client_subtotal' => $result['client_subtotal'],
            'rules' => $result['rules'],
            'markup_total' => $result['markup_total'],
            'fees_total' => $result['fees_total'],
            'discount_total' => $result['discount_total'],
            'grand_total' => $result['grand_total'],
            'priced_at' => $project->priced_at,
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
