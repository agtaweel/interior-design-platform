<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No organization_id (or even project_id) column here by design. Unlike the one-hop
     * precedent (boq_items/pricing_rules/contracts: project_id -> projects.organization_id) or
     * proposal_items' two-hop precedent (proposal_version_id -> proposal_versions.project_id ->
     * projects.organization_id), this table is scoped indirectly through THREE hops:
     *
     *   contract_id -> contracts.project_id -> projects.organization_id
     *
     * See App\Models\PaymentSchedule's docblock for how this is resolved for
     * auditing/tenancy purposes (auditOrganizationId() walks all three hops).
     *
     * Status semantics (PROJECT_CONTEXT.md Sprint 6, deliberate simplification): only two
     * stored values, 'pending' and 'paid' — flips to 'paid' once cumulative recorded payments
     * against this schedule reach its `amount` (backend-api-engineer's job, not a DB
     * constraint). 'Overdue'/'upcoming' are NOT stored — they're computed at read time as
     * `pending AND due_date < today` / `pending AND due_date >= today` respectively (see
     * PaymentSchedule::isOverdue()). No DB CHECK constrains status to these two values, matching
     * this codebase's established convention (projects.status, proposal_versions.status,
     * contracts.status are all plain unconstrained strings).
     */
    public function up(): void
    {
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: a payment schedule is a structural child of a contract —
            // deleting the contract removes its installment plan with it, same convention as
            // proposal_items -> proposal_versions.
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            // e.g. "Deposit", "Milestone 2 - Kitchen Complete", "Final Payment".
            $table->string('name');
            // 1-based ordering within a contract's payment plan; paired with due_date in the
            // recommended index below for the "list ordered by sequence_no" API requirement.
            $table->integer('sequence_no');
            $table->date('due_date');
            // Nullable: a schedule can be created with a flat `amount` directly, OR a
            // `percentage` of contract_value (amount is then computed via bcmath by
            // backend-api-engineer — never float). When percentage-derived, this column is kept
            // for display/traceability ("30% deposit") even though `amount` is the actual
            // decimal source of truth used for payment-matching.
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('pending');
            $table->timestamps();

            // PROJECT_CONTEXT.md "Recommended indexes" lists payment_schedules(contract_id,
            // due_date); this migration also adds (contract_id, sequence_no) per this sprint's
            // explicit schema instructions (list ordered by sequence_no is the primary API
            // access pattern) — both are cheap to carry since contract_id is always the leading
            // column and rows per contract are few.
            $table->index(['contract_id', 'sequence_no']);
            $table->index(['contract_id', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_schedules');
    }
};
