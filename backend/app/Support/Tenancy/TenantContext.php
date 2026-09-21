<?php

namespace App\Support\Tenancy;

use App\Models\OrganizationMember;

/**
 * Per-request holder of "the current organization" for the authenticated user.
 *
 * This is bound as a singleton (see AppServiceProvider::register()) and is populated by
 * App\Http\Middleware\ResolveTenantContext once it has verified the authenticated user holds
 * an active membership in the resolved organization. Everything downstream — the
 * OrganizationScope global scope, the Auditable trait, and User::hasPermission() — reads from
 * this single source of truth rather than re-deriving the organization independently.
 *
 * Deliberately request-scoped, not persisted: a singleton binding is re-created per Laravel
 * bootstrap (i.e. per request in the normal web/octane-less lifecycle), and the middleware
 * clears it in a `finally` block after the response is built, so nothing leaks between
 * requests even under long-running workers.
 */
class TenantContext
{
    private ?int $organizationId = null;

    private ?OrganizationMember $membership = null;

    public function set(int $organizationId, ?OrganizationMember $membership = null): void
    {
        $this->organizationId = $organizationId;
        $this->membership = $membership;
    }

    public function organizationId(): ?int
    {
        return $this->organizationId;
    }

    public function membership(): ?OrganizationMember
    {
        return $this->membership;
    }

    public function hasOrganization(): bool
    {
        return $this->organizationId !== null;
    }

    public function clear(): void
    {
        $this->organizationId = null;
        $this->membership = null;
    }
}
