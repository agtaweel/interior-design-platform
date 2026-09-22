<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No organization_id column here by design (same rationale as boq_categories/boq_items/
     * pricing_rules): this table is scoped indirectly through project_id ->
     * projects.organization_id.
     *
     * Per PROJECT_CONTEXT.md's Sprint 4 "Immutability rule": a version is editable only while
     * status == 'draft'. Once sent, content_json/proposal_items/the totals below become
     * read-only and snapshot_json is finalized. That enforcement is application-layer
     * (backend-api-engineer), not a DB constraint — matches this codebase's existing convention
     * of validating enum-like state at the application layer rather than with CHECK
     * constraints (see pricing_rules migration).
     */
    public function up(): void
    {
        Schema::create('proposal_versions', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: proposal versions are structural/commercial children of a
            // project — deleting the project removes its proposal history too, same convention
            // as boq_categories/boq_items/pricing_rules.
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // Auto-incrementing per project starting at 1 (application-layer responsibility,
            // e.g. max(version_no)+1 inside the create-draft transaction). Unique together with
            // project_id below.
            $table->unsignedInteger('version_no');
            // draft | sent | approved | changes_requested — plain string, no DB enum/CHECK,
            // matching the established convention (projects.status, pricing_rules.type/method/
            // base_selector).
            $table->string('status')->default('draft');
            // subtotal/markup_total/fees_total/discount_total/grand_total: decimal(14,2),
            // mirroring Sprint 3's pricing cache columns on `projects` but one size class up
            // (14 vs 12) since a proposal-wide grand_total sums potentially many BOQ items'
            // client_totals plus multiple rule contributions — headroom against overflow on
            // large commercial projects. Nullable, no default: null means "not yet snapshotted"
            // (a version has these populated at creation time by copying the project's current
            // pricing breakdown — see PROJECT_CONTEXT.md — but the column itself stays
            // nullable to mirror Sprint 3's "null means not yet computed" convention rather
            // than defaulting to a misleading 0.00).
            $table->decimal('subtotal', 14, 2)->nullable();
            $table->decimal('markup_total', 14, 2)->nullable();
            $table->decimal('fees_total', 14, 2)->nullable();
            $table->decimal('discount_total', 14, 2)->nullable();
            $table->decimal('grand_total', 14, 2)->nullable();
            // Editorial content authored in S09 (cover note, scope text, exclusions, timeline,
            // terms, payment plan description) — the ERD doesn't enumerate these as columns, so
            // bundled here per PROJECT_CONTEXT.md's Sprint 4 scope.
            $table->jsonb('content_json')->nullable();
            // The full frozen record at send/approve time: project/client/org info, every
            // proposal_item, the pricing rule breakdown, and content_json's contents. This is
            // what makes a sent/approved version immutable even if the project's BOQ or pricing
            // rules change afterward. Finalized by POST /proposals/{id}/send.
            $table->jsonb('snapshot_json')->nullable();
            // nullOnDelete: preserve the proposal version even if the staff member who created
            // it is later removed from `users` — same rationale as projects.responsible_user_id
            // and audit_logs.actor_user_id.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
            // Recommended index (PROJECT_CONTEXT.md "Recommended indexes"):
            // proposal_versions(project_id, version_no). Also backs the unique-per-project
            // constraint below.
            $table->unique(['project_id', 'version_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposal_versions');
    }
};
