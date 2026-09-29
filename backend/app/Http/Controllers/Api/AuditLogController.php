<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /audit-logs — Platform Readiness Review finding #05 "Audit log viewer". Every commercial
 * mutation across the app is already recorded by App\Models\Concerns\Auditable (see its
 * docblock for the full list of audited models); this is the first surface that lets staff
 * actually read that trail back, rather than it only being queryable via tinker/DB access.
 *
 * Gated behind Permissions::MANAGE_ORGANIZATION — an audit trail spans every commercial entity
 * in the organization (not a single domain like BOQ/procurement/execution), so it's an
 * org-admin/owner concern, the same tier as editing the organization profile itself. No new
 * permission constant was added for this (would require a RoleSeeder backfill for the three
 * already-seeded non-Owner roles) — see Permissions::MANAGE_BOQ's docblock for the established
 * "reuse an existing permission rather than force a data migration" precedent this follows.
 *
 * AuditLog uses BelongsToOrganization (see model), so every query here is already tenant-scoped
 * by the OrganizationScope global scope — no explicit organization_id filter needed.
 */
class AuditLogController extends Controller
{
    /**
     * Paginated, newest first, with optional entity_type/action/actor_user_id filters — mirrors
     * NotificationController::index()'s `paginate($request->integer('per_page', 20))` convention.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_ORGANIZATION);

        $logs = AuditLog::query()
            ->with('actor:id,name')
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('actor_user_id'), fn ($q) => $q->where('actor_user_id', $request->integer('actor_user_id')))
            // Secondary sort by id: created_at has only second-level precision, so two rows
            // written within the same second (common under test/seed conditions, or simply fast
            // consecutive requests) would otherwise tie and fall back to an unspecified order.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return AuditLogResource::collection($logs)->response();
    }
}
