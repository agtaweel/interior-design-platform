<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per PROJECT_CONTEXT.md's Sprint 8 "Notifications" section: directly organization_id AND
     * user_id scoped (per the ERD), same convention as Client/Payment — uses
     * BelongsToOrganization directly rather than an indirect project_id -> organization_id hop,
     * since a notification has no natural parent business record to hang off (it's an inbox
     * item for a user, not a child of a project/contract/etc).
     *
     * user_id cascadeOnDelete (unlike audit_logs.actor_user_id, which is nullOnDelete to
     * preserve audit history after an actor is removed): notifications are ephemeral inbox
     * items, not an audit trail — if a user is removed from the platform, their personal
     * notification inbox should go with them rather than leaving orphaned rows nobody can ever
     * read (notifications are never queried except scoped to their owning user).
     *
     * No Auditable trait: notifications aren't a commercial record needing their own audit
     * trail — auditing a notification would just produce a confusing audit-log-about-an-
     * audit-log-adjacent-thing.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Always 'in_app' for this MVP (no email/SMS/WhatsApp sending infrastructure
            // exists) — plain string, not a DB enum, matching the established convention
            // (projects.status, contracts.status, etc); the column exists so future channels
            // don't require a schema change.
            $table->string('channel')->default('in_app');
            // Short event identifier, e.g. 'proposal_approved' | 'proposal_changes_requested' |
            // 'contract_created' | 'payment_received' | 'change_order_approved' |
            // 'change_order_rejected'. Plain string, no DB enum — same convention as every
            // other status/type column in this codebase.
            $table->string('type');
            // Whatever the frontend needs to render/link the notification: project id/name,
            // entity id, a human-readable summary. Nullable in case a future notification type
            // needs no extra payload beyond its type + timestamps.
            $table->jsonb('payload_json')->nullable();
            // Notifications are created already-sent for this MVP (no queueing/scheduling) —
            // defaults to now() at the DB level so callers never have to pass it explicitly.
            $table->timestamp('sent_at')->useCurrent();
            // Null = unread. Set by POST /notifications/{id}/read or /notifications/read-all.
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            // Primary query pattern per PROJECT_CONTEXT.md: "this user's unread notifications,
            // newest first" -> (user_id, read_at). Combined with created_at ordering this index
            // still serves the unread filter efficiently; a separate covering index isn't
            // needed for MVP volumes.
            $table->index(['user_id', 'read_at']);
            // General list view (all of a user's notifications, newest first).
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
