<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /platform/... routes (BRD v3 §5/§20 "Platform Owner / Super Admin"). Deliberately
 * NOT the `tenant` middleware + a Permissions::* check — a Platform Owner's whole point is
 * cross-tenant visibility, which the organization-scoped Permissions/Role model has no concept
 * of. This middleware runs INSTEAD of `tenant` (see routes/api.php's platform group), so
 * App\Support\Tenancy\TenantContext is never resolved for these requests — every Eloquent query
 * naturally runs unscoped across all organizations, per OrganizationScope's own documented
 * "no tenant context = no restriction" behavior. That is a feature here, not a gap: it's exactly
 * what platform-wide analytics needs.
 */
class EnsurePlatformOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::user()?->is_platform_owner) {
            return response()->json([
                'error' => [
                    'code' => 'platform_owner_required',
                    'message' => 'This action requires platform owner access.',
                    'details' => (object) [],
                ],
            ], 403);
        }

        return $next($request);
    }
}
