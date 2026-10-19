<?php

namespace App\Services\ClientPortal;

use App\Models\Project;
use App\Models\SignedLink;
use App\Support\PublicLinks\SignedLinkService;

/**
 * The client portal link — since docs/SIMPLIFICATION_REVIEW.md P3.1 the ONE link a client gets
 * for a project: they read proposals, the contract, payments, extras and photos there, and
 * approve proposals/change orders there with the 6-digit code staff send them.
 *
 * Portal links are issued "retrievable" (raw token kept encrypted, see SignedLinkService) so:
 *  - staff can copy the current link again at any time (GET /projects/{id}/client-portal-link);
 *  - sending a proposal or change order reuses it — ensure() — and puts it in the WhatsApp
 *    message instead of a new per-document link.
 * Portal links issued before this change were hash-only and can't be shown again; ensure()
 * simply issues a fresh retrievable one alongside (the old one keeps working until revoked).
 */
class ClientPortalLinks
{
    public const PURPOSE = 'client_portal';

    private const EXPIRY_DAYS = 365;

    public function __construct(private readonly SignedLinkService $signedLinks) {}

    /** The project's current, still-valid, retrievable portal token — or null. */
    public function current(Project $project): ?string
    {
        $link = SignedLink::query()
            ->where('purpose', self::PURPOSE)
            ->where('payload_json->project_id', $project->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereNotNull('token_encrypted')
            ->latest('id')
            ->first();

        return $link ? $this->signedLinks->reveal($link) : null;
    }

    /** The current portal token, issuing one if the project has none yet. */
    public function ensure(Project $project, ?int $createdBy): string
    {
        return $this->current($project) ?? $this->issue($project, $createdBy);
    }

    /** Always a new token ("Generate a new link", e.g. after revoking a leaked one). */
    public function issue(Project $project, ?int $createdBy): string
    {
        return $this->signedLinks->issue(
            purpose: self::PURPOSE,
            payload: ['project_id' => $project->id, 'organization_id' => $project->organization_id],
            expiresAt: now()->addDays(self::EXPIRY_DAYS),
            createdBy: $createdBy,
            retrievable: true,
        );
    }

    public function url(string $token, string $path = ''): string
    {
        return sprintf('%s/p/client-portal/%s%s', config('app.frontend_url'), $token, $path);
    }
}
