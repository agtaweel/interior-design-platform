<?php

namespace App\Services\Contracts;

use App\Models\Contract;

/**
 * Builds the payload for GET /contracts/{id}/pdf (PROJECT_CONTEXT.md Sprint 5) — mirrors
 * ProposalPresenter::buildPayload()'s shape/spirit for the same reasons: one place builds
 * "parties + commercial terms" so the Blade view stays a pure renderer. Internal-only surface
 * (no public contract PDF route exists), and contracts carry no BOQ-item-level cost fields to
 * begin with, so unlike ProposalPresenter there's no separate internal/client split to worry
 * about here — this is the only payload shape contracts need.
 */
class ContractPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Contract $contract): array
    {
        $contract->loadMissing(['project.client', 'project.property', 'project.organization', 'proposalVersion']);
        $project = $contract->project;
        $organization = $project?->organization;
        $client = $project?->client;
        $property = $project?->property;
        $proposalVersion = $contract->proposalVersion;

        return [
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'legal_name' => $organization->legal_name,
                'logo_url' => $organization->logo_url,
                'phone' => $organization->phone,
                'email' => $organization->email,
                'currency' => $organization->currency,
            ] : null,
            'project' => $project ? [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
            ] : null,
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'email' => $client->email,
            ] : null,
            'property' => $property ? [
                'id' => $property->id,
                'type' => $property->type,
                'compound' => $property->compound,
                'address' => $property->address,
            ] : null,
            'contract' => [
                'id' => $contract->id,
                'contract_no' => $contract->contract_no,
                'status' => $contract->status,
                'contract_value' => $contract->contract_value,
                'signed_at' => $contract->signed_at,
                'start_date' => $contract->start_date,
                'end_date' => $contract->end_date,
                'proposal_version_no' => $proposalVersion?->version_no,
            ],
            'terms' => $contract->terms_json ?? [],
        ];
    }
}
