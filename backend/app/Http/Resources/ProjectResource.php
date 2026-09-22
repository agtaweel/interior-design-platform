<?php

namespace App\Http\Resources;

use App\Services\Payments\ProjectFinancialsCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /projects and GET /projects/{id} — the S06 "Project Overview" dashboard payload.
 *
 * The `financials` block started as all placeholders in Sprint 1. Each field below is commented
 * with the sprint that populates it (see docs/PROJECT_CONTEXT.md "MVP delivery order") so later
 * agents know exactly where real values were wired in without guessing at the shape the
 * frontend already depends on. This resource is also the seam mentioned in the
 * backend-api-engineer agreement for separating internal-vs-client-facing serializers: once
 * real cost/margin fields exist, a ProjectResource (internal) vs a client-portal-facing
 * equivalent will diverge from here.
 *
 * Sprint 6: `collected`/`outstanding` are now real, computed via ProjectFinancialsCalculator —
 * the SAME class GET /projects/{id}/financials uses (ProjectFinancialsController), per
 * PROJECT_CONTEXT.md's explicit instruction to reuse that calculation rather than duplicate it.
 * A JsonResource isn't constructed through the container, so the calculator is resolved via the
 * `app()` helper here rather than constructor injection — the same pattern this codebase would
 * use for any other resource that needs a service, of which this is the first example.
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $financials = app(ProjectFinancialsCalculator::class)->calculate($this->resource);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status,
            'client' => new ClientSummaryResource($this->whenLoaded('client')),
            'property' => new PropertySummaryResource($this->whenLoaded('property')),
            'responsible_user' => new UserSummaryResource($this->whenLoaded('responsibleUser')),
            'start_date' => $this->start_date,
            'target_end_date' => $this->target_end_date,
            'members' => ProjectMemberResource::collection($this->whenLoaded('projectMembers')),
            'services' => ProjectServiceResource::collection($this->whenLoaded('services')),
            'financials' => [
                // value: Sprint 3 (Pricing) populates this from the project's cached
                // `grand_total` (POST /projects/{id}/pricing/recalculate), per
                // PROJECT_CONTEXT.md's explicit instruction ("value in financials SHOULD now
                // populate from grand_total"). Null-coalesced to 0 rather than left null: a
                // project that has never been priced should read as "0", not force every
                // consumer to special-case null. Superseded again in Sprint 4/5 once an
                // approved proposal/contract snapshot exists (at that point the commercial
                // value comes from the signed snapshot, not the live pricing cache).
                'value' => $this->grand_total ?? 0,
                // collected: sum of recorded payments.amount for this project. Bare int 0 if
                // none recorded yet (matching `value`'s own 0-fallback convention above), a
                // full-precision bcmath decimal string otherwise — see
                // ProjectFinancialsCalculator::zeroAsInt()'s docblock.
                'collected' => $financials['collected'],
                // outstanding: the project's contract's contract_value minus collected, clamped
                // at 0, or 0 if no contract exists yet — see
                // ProjectFinancialsCalculator::resolveOutstanding()'s docblock for why "no
                // contract" reads as 0 rather than the priced `value`.
                'outstanding' => $financials['outstanding'],
                // actual_cost: sum of recorded expenses / actual BOQ execution cost.
                // Populated starting Sprint 2/3 (BOQ & Pricing) and refined in Sprint 7
                // (Change Orders).
                'actual_cost' => 0,
                // gross_profit: value - actual_cost. Null (not zero) until there is a value
                // to subtract from, so the frontend can distinguish "no data yet" from
                // "zero profit". Populated once Sprints 4-6 land.
                'gross_profit' => null,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
