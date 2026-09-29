<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Finance\FinancialLedgerService;
use Illuminate\Http\JsonResponse;

/**
 * GET /platform/analytics/summary, GET /platform/organizations[/{organization}]
 * (BRD v3 §5/§20 "Platform Owner / Super Admin"). Gated behind the `platform.owner` middleware
 * (EnsurePlatformOwner), not `tenant` — every query in this controller is intentionally
 * UNSCOPED across every organization, since that is the entire point of this role. See
 * EnsurePlatformOwner's docblock for why no tenant context existing is what makes this safe
 * (OrganizationScope's own documented behavior).
 */
class PlatformAnalyticsController extends Controller
{
    public function __construct(private readonly FinancialLedgerService $ledger) {}

    public function summary(): JsonResponse
    {
        $projectStatusCounts = Project::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'data' => [
                'organizations_count' => Organization::query()->count(),
                'projects_count' => Project::query()->count(),
                'projects_by_status' => $projectStatusCounts,
                'users_count' => User::query()->count(),
                'financials' => $this->ledger->platformSummary(),
            ],
        ]);
    }

    public function organizations(): JsonResponse
    {
        $organizations = Organization::query()
            ->withCount(['users as members_count'])
            ->orderBy('name')
            ->get();

        $projectCounts = Project::query()
            ->selectRaw('organization_id, count(*) as count')
            ->groupBy('organization_id')
            ->pluck('count', 'organization_id');

        return response()->json([
            'data' => $organizations->map(fn (Organization $org) => [
                'id' => $org->id,
                'name' => $org->name,
                'legal_name' => $org->legal_name,
                'currency' => $org->currency,
                'members_count' => $org->members_count,
                'projects_count' => $projectCounts[$org->id] ?? 0,
                'created_at' => $org->created_at,
            ])->values(),
        ]);
    }

    public function organization(string $organization): JsonResponse
    {
        $org = Organization::query()->find($organization);

        if (! $org) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                    'details' => (object) [],
                ],
            ], 404);
        }

        $financials = $this->ledger->organizationSummary($org->id);

        return response()->json([
            'data' => [
                'id' => $org->id,
                'name' => $org->name,
                'legal_name' => $org->legal_name,
                'currency' => $org->currency,
                'members_count' => $org->users()->count(),
                'projects_count' => Project::query()->where('organization_id', $org->id)->count(),
                'projects_by_status' => Project::query()
                    ->where('organization_id', $org->id)
                    ->selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status'),
                'financials' => $financials,
                'created_at' => $org->created_at,
            ],
        ]);
    }
}
