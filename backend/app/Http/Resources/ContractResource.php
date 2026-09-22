<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /contracts/{id} and the response of POST .../contracts/from-proposal/{id}
 * (PROJECT_CONTEXT.md Sprint 5). Internal-only (auth:sanctum + tenant, no public contract
 * surface exists) — still, contracts carry no BOQ-item-level cost fields at all (unlike
 * proposals/BOQ), so there's no cost/margin leakage risk here to begin with.
 *
 * `project`/`client` are minimal summaries (ProjectSummaryResource/ClientSummaryResource,
 * already used elsewhere for embedding), not the full resources — a contract detail view needs
 * "whose contract is this", not the full project/client record. `proposal_version` is a bare
 * id/version_no reference back to the source snapshot per PROJECT_CONTEXT.md's "read-only
 * reference back to the source proposal version" requirement — not the full proposal detail,
 * which is already available via GET /proposals/{id} for anyone who needs it.
 */
class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $project = $this->whenLoaded('project');
        $proposalVersion = $this->whenLoaded('proposalVersion');

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'contract_no' => $this->contract_no,
            'status' => $this->status,
            'contract_value' => $this->contract_value,
            'signed_at' => $this->signed_at,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'terms_json' => $this->terms_json,
            'project' => $project ? new ProjectSummaryResource($project) : null,
            'client' => $project && $project->relationLoaded('client') && $project->client
                ? new ClientSummaryResource($project->client)
                : null,
            'proposal_version' => $proposalVersion ? [
                'id' => $proposalVersion->id,
                'version_no' => $proposalVersion->version_no,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
