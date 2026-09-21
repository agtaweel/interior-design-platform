<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Automatically constrains every query against a tenant model to the organization resolved
 * for the current request by App\Http\Middleware\ResolveTenantContext.
 *
 * Behaviour:
 *  - If a tenant context IS resolved (the normal case for any authenticated HTTP request that
 *    passed through the `tenant` middleware), every query is constrained to
 *    `organization_id = <current org> OR organization_id IS NULL`. The `OR IS NULL` half only
 *    ever matches rows on models that legitimately allow a null organization_id (currently just
 *    `Role`, for global template roles such as Owner/Admin/Designer) — for every other tenant
 *    table organization_id is NOT NULL, so this is equivalent to a plain equality filter there.
 *  - If NO tenant context is resolved (console commands, queued jobs, artisan tinker, model
 *    factories in tests, etc.) the scope does not restrict anything. Enforcement for real HTTP
 *    traffic comes from the `tenant` middleware being mandatory on every route that touches
 *    tenant data — see routes/api.php — not from this scope trying to guess whether it's "safe"
 *    to leave a query unscoped. Code that runs outside the request lifecycle (jobs, console
 *    commands) is expected to explicitly scope its own queries.
 *
 * This only covers SELECT/UPDATE/DELETE built through the Eloquent query builder for the model
 * (including mass updates/deletes). It does NOT stop a raw INSERT from setting an arbitrary
 * organization_id — that side of tenant isolation (writes) is covered separately by the
 * saving-guard registered in App\Models\Concerns\BelongsToOrganization.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->hasOrganization()) {
            return;
        }

        $column = $model->qualifyColumn('organization_id');

        $builder->where(function (Builder $query) use ($column, $context) {
            $query->where($column, $context->organizationId())
                ->orWhereNull($column);
        });
    }
}
