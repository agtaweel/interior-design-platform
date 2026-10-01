<?php

namespace App\Services\Marketplace;

use App\Models\ClientUser;
use App\Models\Project;
use App\Models\ProposalVersion;
use Illuminate\Database\Eloquent\Collection;

/**
 * BRD v4 "Client Marketplace" Phase C — the client-auth equivalent of
 * PublicClientPortalController::resolveProjectFromToken(). Every `/client/projects/*` and
 * `/client/deals/*` endpoint sits outside the `tenant` middleware (see EnsureClientUser's
 * docblock), so OrganizationScope provides zero automatic protection — this is the single place
 * that answers "does this project/proposal actually belong to this logged-in client", so that
 * answer is defined once rather than re-implemented (and potentially gotten wrong) per
 * controller.
 */
class ClientOwnershipResolver
{
    /**
     * @return Collection<int, Project>
     */
    public function projectsFor(ClientUser $clientUser): Collection
    {
        return Project::query()
            ->whereHas('client', fn ($q) => $q->where('client_user_id', $clientUser->id))
            ->orderByDesc('created_at')
            ->get();
    }

    public function resolveProject(ClientUser $clientUser, string $projectId): ?Project
    {
        return Project::query()
            ->whereHas('client', fn ($q) => $q->where('client_user_id', $clientUser->id))
            ->find($projectId);
    }

    /**
     * Confirms the requested proposal version belongs to a project owned by this client —
     * without this check, a client could fetch another client's deal just by guessing/
     * incrementing the id in the URL. Draft versions are excluded, same as
     * PublicClientPortalController::resolveProposalVersion() — a client never sees a proposal
     * before staff sends it.
     */
    public function resolveProposalVersion(ClientUser $clientUser, string $proposalVersionId): ?ProposalVersion
    {
        $version = ProposalVersion::query()->where('status', '!=', 'draft')->find($proposalVersionId);

        if (! $version || ! $this->resolveProject($clientUser, (string) $version->project_id)) {
            return null;
        }

        return $version;
    }
}
