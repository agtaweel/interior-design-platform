<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\Authorization\Permissions;
use App\Support\PublicLinks\SignedLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /projects/{project}/client-portal-link, POST /projects/{project}/client-portal-link/revoke
 * (BRD v3 §17 "Client Portal"). Issues/revokes the long-lived, multi-use SignedLink
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
    private const PURPOSE = 'client_portal';

    public function __construct(private readonly SignedLinkService $signedLinkService) {}

    public function store(Request $request, string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROJECTS);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $token = $this->signedLinkService->issue(
            purpose: self::PURPOSE,
            payload: ['project_id' => $projectModel->id, 'organization_id' => $projectModel->organization_id],
            expiresAt: now()->addYear(),
            createdBy: $request->user()->id,
        );

        return response()->json(['data' => ['token' => $token]], 201);
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
