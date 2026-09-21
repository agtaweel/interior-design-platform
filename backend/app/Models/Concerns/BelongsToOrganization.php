<?php

namespace App\Models\Concerns;

use App\Exceptions\TenantMismatchException;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as directly tenant-scoped via an `organization_id` column, and enforces tenant
 * isolation for both reads and writes:
 *
 *  - Reads: registers App\Models\Scopes\OrganizationScope as a global scope, so every query
 *    built through this model is automatically constrained to the current tenant context (see
 *    that class for exact behaviour, including the nullable-organization_id allowance used by
 *    global template Roles).
 *  - Writes: a `saving` listener that closes the gap global scopes can't cover (they don't
 *    constrain plain INSERTs). If organization_id is omitted entirely, it's auto-filled from
 *    the current tenant context. If it's explicitly set and does NOT match the current tenant
 *    context, the save is rejected with a TenantMismatchException (rendered as 403) rather than
 *    silently creating/updating a row that belongs to another organization. If organization_id
 *    is explicitly set to null (used only for seeding global template Roles) it is left alone.
 *    All of this only applies when a tenant context IS resolved; outside the request lifecycle
 *    (seeders, factories, console commands) callers are responsible for setting
 *    organization_id themselves.
 */
trait BelongsToOrganization
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::saving(function (Model $model) {
            $context = app(TenantContext::class);

            if (! $context->hasOrganization()) {
                return;
            }

            $attributes = $model->getAttributes();

            if (! array_key_exists('organization_id', $attributes)) {
                $model->setAttribute('organization_id', $context->organizationId());

                return;
            }

            if ($attributes['organization_id'] === null) {
                // Explicit null is only valid for global template records (e.g. Role); leave
                // it untouched rather than forcing it into the current org.
                return;
            }

            if ((int) $attributes['organization_id'] !== (int) $context->organizationId()) {
                throw new TenantMismatchException(sprintf(
                    'Refusing to save %s with organization_id=%s outside the current tenant context (organization_id=%s).',
                    $model::class,
                    $attributes['organization_id'],
                    $context->organizationId(),
                ));
            }
        });
    }
}
