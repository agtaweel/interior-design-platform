<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /projects and GET /projects/{id} — the S06 "Project Overview" dashboard payload.
 *
 * The `financials` block is all placeholders for Sprint 1: this model has no pricing/payment
 * data yet. Each field below is commented with the sprint that will populate it (see
 * docs/PROJECT_CONTEXT.md "MVP delivery order") so later agents know exactly where to wire
 * real values in without guessing at the shape the frontend already depends on. This resource
 * is also the seam mentioned in the backend-api-engineer agreement for separating
 * internal-vs-client-facing serializers: once real cost/margin fields exist, a
 * ProjectResource (internal) vs a client-portal-facing equivalent will diverge from here.
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
                // value: contract/proposal commercial value. Populated in Sprint 4
                // (Proposals) once an approved proposal snapshot exists, then superseded by
                // the Sprint 5 (Approval + Contract) snapshot once the project has a signed
                // contract.
                'value' => 0,
                // collected: sum of recorded payments. Populated in Sprint 6 (Payments).
                'collected' => 0,
                // outstanding: value - collected. Populated in Sprint 6 (Payments).
                'outstanding' => 0,
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
