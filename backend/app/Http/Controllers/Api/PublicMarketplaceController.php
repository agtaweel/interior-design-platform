<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOrganizationResource;
use App\Http\Resources\PublicOrganizationSummaryResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /public/marketplace/organizations[/{organization}] (BRD v4 "Client Marketplace") —
 * deliberately OUTSIDE both the auth:sanctum and `tenant` middleware groups, same
 * token-free-but-still-restricted posture as the existing public proposal/change-order/
 * client-portal routes (though this one needs no token at all — browsing is meant to be
 * anonymous). Every query here is filtered to `whereHas('profile', fn ($q) =>
 * $q->where('is_marketplace_listed', true))` — an organization that hasn't opted in is never
 * reachable through this controller, not even by guessing its id.
 */
class PublicMarketplaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Organization::query()
            ->whereHas('profile', fn ($q) => $q->where('is_marketplace_listed', true))
            ->with('profile');

        if ($search = $request->query('q')) {
            // LOWER()+LIKE, not `ilike` — see ClientController::index()'s identical comment:
            // `ilike` is Postgres-only and breaks the sqlite-backed test suite.
            $needle = mb_strtolower($search);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"]);
        }

        $organizations = $query->orderBy('name')->paginate($request->integer('per_page', 20));

        return PublicOrganizationSummaryResource::collection($organizations)->response();
    }

    public function show(string $organization): JsonResponse
    {
        $organizationModel = Organization::query()
            ->whereHas('profile', fn ($q) => $q->where('is_marketplace_listed', true))
            ->with('profile')
            ->find($organization);

        if (! $organizationModel) {
            return $this->notFound();
        }

        return response()->json(['data' => new PublicOrganizationResource($organizationModel)]);
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
