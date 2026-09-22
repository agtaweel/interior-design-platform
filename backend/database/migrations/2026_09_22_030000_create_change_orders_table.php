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
     * pricing_rules/proposal_versions/contracts): this table is scoped indirectly through
     * project_id -> projects.organization_id.
     *
     * Per PROJECT_CONTEXT.md's Sprint 7 lifecycle: draft -> sent -> approved|rejected ->
     * applied (only reachable from approved). Enforcing that transition ordering is
     * application-layer (backend-api-engineer), not a DB constraint — matches this codebase's
     * existing convention of validating state machines at the application layer rather than
     * with CHECK constraints (see proposal_versions/contracts migrations).
     *
     * No `approved_by` column: per PROJECT_CONTEXT.md's explicit reasoning, the `approvals`
     * table (entity_type = 'change_order') is already the detailed record of WHO approved —
     * same pattern as proposal_versions.approved_at having no paired user_id, since the
     * approver may be a client (not a `users` row) rather than staff.
     */
    public function up(): void
    {
        Schema::create('change_orders', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: a change order is a commercial child of a project — deleting
            // the project removes its change-order history too, same convention as
            // boq_categories/boq_items/pricing_rules/proposal_versions/contracts.
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // Human-facing code, e.g. "CO-00001" — same auto-generation + conflict-fallback
            // pattern as projects.code / contracts.contract_no (implemented by
            // backend-api-engineer; this migration only adds the column). Globally unique,
            // matching contracts.contract_no's uniqueness style since this table also has no
            // organization_id column of its own to compound against.
            $table->string('number')->unique();
            // draft | sent | approved | rejected | applied — plain string, no DB enum/CHECK,
            // matching the established convention (projects.status, proposal_versions.status,
            // contracts.status).
            $table->string('status')->default('draft');
            $table->text('reason');
            // Computed from the sum of change_order_items.line_delta (application-layer,
            // never a manually-entered total — same "compute from lines" principle as
            // proposal_versions.grand_total). Nullable, no default: null means "not yet
            // computed" (e.g. before any items are added), mirroring Sprint 3/4's
            // "null means not yet computed" convention rather than defaulting to a misleading
            // 0.00. decimal(14,2) matches proposal_versions'/contracts' money-total precision.
            $table->decimal('price_delta', 14, 2)->nullable();
            // Signed — can be negative for a schedule pull-forward (per PROJECT_CONTEXT.md).
            // Nullable: a change order may carry no timeline impact at all.
            $table->integer('timeline_delta_days')->nullable();
            // nullOnDelete: preserve the change order even if the staff member who requested it
            // is later removed from `users` — same rationale as proposal_versions.created_by.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // Beyond the ERD's literal column list — added per PROJECT_CONTEXT.md's explicit
            // instruction to mark when the Apply step (the one controlled exception to
            // contracts' immutability rule) actually ran, distinct from approved_at.
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('change_orders');
    }
};
