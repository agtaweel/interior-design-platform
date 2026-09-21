<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves "the current organization" for a request and rejects the request if the
 * authenticated user has no active membership there.
 *
 * Must run AFTER an auth middleware (e.g. `auth:sanctum`) so $request->user() is available.
 * Resolution order (first match wins):
 *
 *   1. A route-model-bound `{organization}` parameter — used by organization-nested routes
 *      such as POST /organizations/{organization}/members/invite. This lets a single
 *      middleware serve both "implicit context" endpoints (clients/projects/etc, which take
 *      the org from the user) and "explicit context" endpoints (organization management
 *      routes, which take the org from the URL) without duplicating the membership check.
 *   2. An `X-Organization-Id` request header — for the common REST endpoints
 *      (/clients, /projects, ...) that are not nested under /organizations/{id} per the PRD's
 *      API spec, but still need to know which of the user's organizations they're operating
 *      in. A user who belongs to multiple organizations MUST send this header explicitly.
 *   3. If the header/route param is absent and the user has exactly one ACTIVE membership,
 *      that organization is used as an implicit default — this keeps the common single-org
 *      case (most users, most of the time) header-free.
 *
 * If none of the above resolves to a single organization id, or the user has no active
 * membership in the resolved organization, the request is rejected before it reaches any
 * controller or Eloquent query — this is the enforcement point that makes the OrganizationScope
 * global scope (see App\Models\Scopes\OrganizationScope) safe to trust: by the time a
 * controller runs, TenantContext is guaranteed to hold an organization the user is an active
 * member of, or the request never got this far.
 */
class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->error(401, 'unauthenticated', 'Authentication required.');
        }

        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return $this->error(
                400,
                'organization_context_required',
                'Could not determine which organization this request applies to. Send an X-Organization-Id header.',
            );
        }

        $membership = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return $this->error(
                403,
                'no_active_membership',
                'You do not have an active membership in this organization.',
            );
        }

        $context = app(TenantContext::class);
        $context->set($organizationId, $membership);

        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }

    private function resolveOrganizationId(Request $request): ?int
    {
        $routeOrganization = $request->route('organization');

        if ($routeOrganization instanceof Organization) {
            return $routeOrganization->id;
        }

        if (is_numeric($routeOrganization)) {
            return (int) $routeOrganization;
        }

        if ($request->hasHeader('X-Organization-Id')) {
            $header = $request->header('X-Organization-Id');

            return is_numeric($header) ? (int) $header : null;
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        $activeOrganizationIds = $user->organizationMemberships()
            ->where('status', 'active')
            ->pluck('organization_id');

        return $activeOrganizationIds->count() === 1
            ? (int) $activeOrganizationIds->first()
            : null;
    }

    private function error(int $status, string $code, string $message): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) [],
            ],
        ], $status);
    }
}
