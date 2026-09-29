<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /audit-logs — Platform Readiness Review finding #05 (Audit log viewer). Surfaces exactly
 * what App\Models\Concerns\Auditable already records: no cost/margin scrubbing concern here
 * beyond what that trait itself already strips (password/remember_token/hidden fields, see its
 * docblock) — before/after snapshots are shown verbatim since this is a MANAGE_ORGANIZATION-gated
 * admin surface, not a client-facing one.
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor' => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'action' => $this->action,
            'before' => $this->before_json,
            'after' => $this->after_json,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at,
        ];
    }
}
