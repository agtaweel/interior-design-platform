<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as directly tenant-scoped via an `organization_id` column.
 *
 * This trait currently only wires the `organization()` relation. It deliberately does NOT
 * register a global scope yet — auth-security-engineer owns tenant-context resolution
 * (how we know "the current organization" from the authenticated request) and will add the
 * enforcement here, e.g.:
 *
 *     protected static function booted(): void
 *     {
 *         static::addGlobalScope(new \App\Models\Scopes\OrganizationScope);
 *     }
 *
 * Until that lands, every query against a tenant table MUST be manually scoped by the
 * caller (e.g. ->where('organization_id', $orgId)) — do not assume isolation is automatic.
 */
trait BelongsToOrganization
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
