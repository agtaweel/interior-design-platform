<?php

namespace App\Http\Middleware;

use App\Models\ClientUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /client/... routes (BRD v4 "Client Marketplace"). Exact mirror of
 * EnsurePlatformOwner's structure and reasoning: a marketplace `ClientUser` belongs to no
 * organization, so this middleware runs INSTEAD of `tenant` (see routes/api.php's client group),
 * and App\Support\Tenancy\TenantContext is never resolved for these requests — every client-facing
 * controller must therefore resolve "which rows belong to this client" manually (see
 * App\Services\Marketplace\ClientOwnershipResolver), the same way PublicClientPortalController
 * resolves ownership from a token instead of a tenant context.
 */
class EnsureClientUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::user() instanceof ClientUser) {
            return response()->json([
                'error' => [
                    'code' => 'client_account_required',
                    'message' => 'This action requires a client account.',
                    'details' => (object) [],
                ],
            ], 403);
        }

        return $next($request);
    }
}
