<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SignedLink;
use App\Services\ClientPortal\ClientPortalLinks;
use App\Support\Authorization\Permissions;
use App\Support\PublicLinks\SignedLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET/POST /projects/{project}/client-portal-link, POST /projects/{project}/client-portal-link/revoke
 * (BRD v3 §17 "Client Portal").
 *
 * Since docs/SIMPLIFICATION_REVIEW.md P3.1 the portal link is the one link a client gets, so it
 * is issued retrievable (see ClientPortalLinks): GET returns the current link to copy again, and
 * revoke without a token revokes every portal link of the project (staff no longer hold a
 * one-time copy of each). Issues/revokes the long-lived, multi-use SignedLink
 * (purpose='client_portal') that PublicClientPortalController verifies — unlike the
 * single-purpose, single-use proposal/change-order approval links, this token is meant to be
 * bookmarked by the client and reused across many visits, so it's issued with a long expiry
 * (1 year) and verify() never consumes it.
 *
 * Gated behind Permissions::MANAGE_PROJECTS — sharing portal access to a project is a
 * project-management action, same tier as editing the project itself.
 */
class ClientPortalLinkController extends Controller
{
    public function __construct(
        private readonly SignedLinkService $signedLinkService,
        private readonly ClientPortalLinks $portalLinks,
    ) {}

    public function show(string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROJECTS);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $token = $this->portalLinks->current($projectModel);

        return response()->json([
            'data' => $token ? ['token' => $token, 'url' => $this->portalLinks->url($token)] : null,
        ]);
    }

    public function store(Request $request, string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROJECTS);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $token = $this->portalLinks->issue($projectModel, $request->user()->id);

        return response()->json(['data' => ['token' => $token, 'url' => $this->portalLinks->url($token)]], 201);
    }

    public function revoke(Request $request, string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROJECTS);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $token = $request->string('token')->toString();

        if ($token !== '') {
            $this->signedLinkService->revoke($token);
        } else {
            SignedLink::query()
                ->where('purpose', ClientPortalLinks::PURPOSE)
                ->where('payload_json->project_id', $projectModel->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        return response()->json(status: 204);
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
