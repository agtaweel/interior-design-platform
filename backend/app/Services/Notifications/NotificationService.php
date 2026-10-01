<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Support\Authorization\Permissions;

/**
 * PROJECT_CONTEXT.md Sprint 8 "Notifications". Single entry point every commercial-workflow
 * trigger point (proposal approve/request-changes, contract creation, payment recording, change
 * order approve/reject) calls into — no controller/service creates a `notifications` row
 * directly, so "who gets notified" is defined in exactly one place.
 *
 * **Who gets notified** (verbatim from PROJECT_CONTEXT.md): the project's `responsible_user_id`
 * if set; otherwise every organization member holding `Permissions::MANAGE_BOQ`. This is a
 * reasonable fallback so a notification is never silently dropped for a project with no
 * responsible user assigned yet.
 *
 * `channel` is always `'in_app'` for this MVP (no email/SMS/WhatsApp sending infrastructure
 * exists — see Notification migration's docblock).
 */
final class NotificationService
{
    /**
     * @param  array<string, mixed>  $payload  Whatever the frontend needs to render/link the
     *                                          notification — project id/name, entity id/number,
     *                                          a short human summary string. Merged as-is into
     *                                          `payload_json`, never inspected here.
     */
    public function notify(Project $project, string $type, array $payload): void
    {
        foreach ($this->resolveRecipientUserIds($project) as $userId) {
            Notification::create([
                'organization_id' => $project->organization_id,
                'user_id' => $userId,
                'channel' => 'in_app',
                'type' => $type,
                'payload_json' => $payload,
            ]);
        }
    }

    /**
     * BRD v4 "Client Marketplace" — notifies an organization's staff about something that isn't
     * scoped to a Project (a new marketplace inquiry can arrive before any project exists, so
     * notify() above, which hard-requires one, doesn't fit). Every active member holding
     * `$permission` gets a row; there's no single `responsible_user_id` fallback because an
     * inquiry has no owner yet — that's exactly what this notification is for.
     *
     * @param  array<string, mixed>  $payload
     */
    public function notifyOrganization(Organization $organization, string $type, array $payload, string $permission = Permissions::MANAGE_CLIENTS): void
    {
        $userIds = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->with('role')
            ->get()
            ->filter(fn (OrganizationMember $member): bool => (bool) ($member->role?->permissions_json[$permission] ?? false))
            ->pluck('user_id')
            ->unique()
            ->values();

        foreach ($userIds as $userId) {
            Notification::create([
                'organization_id' => $organization->id,
                'user_id' => $userId,
                'channel' => 'in_app',
                'type' => $type,
                'payload_json' => $payload,
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function resolveRecipientUserIds(Project $project): array
    {
        if ($project->responsible_user_id !== null) {
            return [$project->responsible_user_id];
        }

        return OrganizationMember::query()
            ->where('organization_id', $project->organization_id)
            ->where('status', 'active')
            ->with('role')
            ->get()
            ->filter(fn (OrganizationMember $member): bool => (bool) ($member->role?->permissions_json[Permissions::MANAGE_BOQ] ?? false))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }
}
