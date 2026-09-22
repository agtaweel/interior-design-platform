<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /organizations/{organization} (PROJECT_CONTEXT.md Sprint 8 "Settings" — organization
 * profile/branding). {organization} uses implicit route-model binding, same convention already
 * established by OrganizationMemberController::invite()/update() — unlike Project/Client/
 * Property, Organization carries no OrganizationScope of its own to worry about being applied
 * too early (it IS the tenant, not a tenant-scoped child record), so implicit binding here is
 * safe regardless of middleware ordering.
 *
 * Belt-and-suspenders tenant check: ResolveTenantContext already derives the current tenant
 * from this exact same route parameter (see that middleware's docblock, resolution order #1),
 * so $organization->id and TenantContext::organizationId() should always agree by construction
 * — but PROJECT_CONTEXT.md explicitly asks for an independent check here anyway ("reject/ignore
 * attempts to patch a different org id than the current tenant, even though tenant middleware
 * should already prevent cross-org access") since this is a new, not-yet-battle-tested endpoint.
 */
class OrganizationController extends Controller
{
    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        if ($organization->id !== app(TenantContext::class)->organizationId()) {
            return $this->error(403, 'tenant_mismatch', 'You cannot modify a different organization than your current tenant context.');
        }

        $organization->update($request->validated());

        return response()->json([
            'data' => new OrganizationResource($organization->fresh()),
        ]);
    }

    private function error(int $status, string $code, string $message): JsonResponse
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
