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
     * pricing_rules/proposal_versions): this table is scoped indirectly through project_id ->
     * projects.organization_id.
     *
     * Per PROJECT_CONTEXT.md's Sprint 5 "Key design decision": contract creation IS the
     * signing act (the client already signed via Sprint 4's OTP-gated proposal approval) — a
     * contract is created active, with signed_at set at creation, no draft/pending-signature
     * state.
     *
     * Immutability boundary (distinct from proposal_versions'): contract_value and
     * proposal_version_id are permanently locked at creation — enforced here at the DB level
     * (the unique constraint on proposal_version_id backs the ERD's "Approved Proposal
     * Version 1—0..1 Contract" relationship; the "never recalculated" half of contract_value's
     * immutability is an application-layer rule, same convention as proposal_versions' own
     * immutability rule having no CHECK-constraint backing). start_date/end_date/terms_json,
     * by contrast, are freely editable after creation — that's what Auditable's before/after
     * trail exists to make a "controlled amendment" per this sprint's scope decision.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: a contract is a structural/commercial child of a project —
            // deleting the project removes its contract too, same convention as
            // boq_categories/boq_items/pricing_rules/proposal_versions.
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // unique: enforces the ERD's "Approved Proposal Version 1—0..1 Contract"
            // relationship at the DB level, not just app logic — a proposal version can back
            // at most one contract. restrictOnDelete (not cascade/null): a contract must never
            // be silently orphaned by a deleted proposal version — the source commercial
            // snapshot this contract was signed from must stay in place for as long as the
            // contract exists.
            $table->foreignId('proposal_version_id')->unique()->constrained('proposal_versions')->restrictOnDelete();
            // Human-facing contract code, e.g. "CTR-00001" — same auto-generation +
            // conflict-fallback pattern as projects.code (implemented by backend-api-engineer;
            // this migration only adds the column). Globally unique, matching projects.code's
            // uniqueness style but without the per-organization compound key since contracts
            // don't have their own organization_id column to compound against.
            $table->string('contract_no')->unique();
            // active | completed | terminated — plain string, no DB enum/CHECK, matching the
            // established convention (projects.status, proposal_versions.status). Only
            // 'active' is actually produced/transitioned-to this sprint; the others are
            // reserved for a future sprint.
            $table->string('status')->default('active');
            // Direct immutable copy of the source proposal_versions.grand_total at creation
            // time — decimal(14,2) matches that column's precision exactly since this is a
            // straight copy, never recalculated. Locking this is an application-layer rule
            // (backend-api-engineer), same convention as proposal_versions' own immutability.
            $table->decimal('contract_value', 14, 2);
            // Nullable at the DB level for safety even though the app layer always sets this
            // at creation time (creation IS signing, per the locked "OTP not e-signature"
            // decision) — mirrors this codebase's general preference for nullable timestamps
            // over a NOT NULL that would fight migration/backfill edge cases.
            $table->timestamp('signed_at')->nullable();
            // Freely editable after creation via PATCH — not locked like contract_value/
            // proposal_version_id above.
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Seeded from the source proposal's content_json (terms/exclusions/timeline/
            // payment_plan sections) at creation time as the contract's own independent copy,
            // then freely editable — never re-reads from the proposal afterward, same
            // "frozen copy, not a live reference" discipline as proposal_items vs boq_items.
            $table->jsonb('terms_json')->nullable();
            $table->timestamps();

            $table->index('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
