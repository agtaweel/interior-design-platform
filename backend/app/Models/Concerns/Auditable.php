<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Generic audit-trail wiring: writes an audit_logs row (actor_user_id, entity_type, entity_id,
 * action, before_json, after_json, ip_address) whenever a model using this trait is created,
 * updated, or deleted. Intended to be reused by every commercially-significant model added in
 * later sprints (proposals, contracts, payments, change orders), not just Sprint 1's
 * clients/properties/projects/leads/organization_members.
 *
 * Deliberately NOT applied to App\Models\User (would risk capturing password hashes in the
 * audit trail even with the getHidden() stripping below — safer to just never audit the users
 * table) or App\Models\AuditLog itself (would recurse).
 *
 * organization_id resolution for the audit row: uses the model's own `organization_id`
 * attribute when present (every Sprint 1 auditable model has one directly), falling back to
 * the current TenantContext. Models that are tenant-scoped only indirectly (e.g. a future
 * model with no organization_id column of its own) should override
 * `auditOrganizationId(): ?int` to resolve it via their parent relation.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            static::recordAudit($model, 'created', null, static::auditableAttributes($model));
        });

        static::updated(function (Model $model) {
            $changes = Arr::except($model->getChanges(), ['updated_at']);

            if (empty($changes)) {
                return;
            }

            $before = Arr::only($model->getOriginal(), array_keys($changes));

            static::recordAudit($model, 'updated', static::stripHidden($model, $before), static::stripHidden($model, $changes));
        });

        static::deleted(function (Model $model) {
            static::recordAudit($model, 'deleted', static::auditableAttributes($model), null);
        });
    }

    private static function auditableAttributes(Model $model): array
    {
        return static::stripHidden($model, $model->getAttributes());
    }

    private static function stripHidden(Model $model, array $attributes): array
    {
        return Arr::except($attributes, array_merge($model->getHidden(), ['password', 'remember_token']));
    }

    private static function recordAudit(Model $model, string $action, ?array $before, ?array $after): void
    {
        $organizationId = method_exists($model, 'auditOrganizationId')
            ? $model->auditOrganizationId()
            : ($model->getAttribute('organization_id') ?? app(TenantContext::class)->organizationId());

        if (! $organizationId) {
            // Can't attribute this change to an organization (e.g. ran outside any tenant
            // context, such as a console seeder) — skip rather than write a row that would
            // violate audit_logs.organization_id's NOT NULL constraint.
            return;
        }

        AuditLog::create([
            'organization_id' => $organizationId,
            'actor_user_id' => auth()->id(),
            'entity_type' => class_basename($model),
            'entity_id' => $model->getKey(),
            'action' => $action,
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => request()?->ip(),
        ]);
    }
}
