<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Unlike proposal_versions/proposal_items above, this table IS directly organization-scoped
     * (per the ERD) — it carries organization_id directly rather than being derived through a
     * parent relation, same pattern as audit_logs.
     */
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: matches audit_logs' rationale — an approval record only makes
            // sense in the context of its organization; full tenant teardown takes it with it.
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // Polymorphic-by-convention (not a real Eloquent morph), same pattern already used
            // by audit_logs.entity_type/entity_id — e.g. "ProposalVersion" now, "ChangeOrder"
            // from Sprint 7 onward. Kept generic per PROJECT_CONTEXT.md's Sprint 4 scope.
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            // client | internal — who performed the approval action.
            $table->string('approver_type');
            // nullable: a client isn't a `users` row, so client approvals leave this null.
            // nullOnDelete: preserve the approval record even if the acting internal user is
            // later removed from `users`.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // approved | changes_requested | rejected — plain string, no DB enum/CHECK, matching
            // the established convention.
            $table->string('status');
            $table->text('comment')->nullable();
            $table->timestamp('approved_at')->nullable();
            // IPv6-safe length, matching audit_logs.ip_address.
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'entity_type', 'entity_id']);
            $table->index('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
