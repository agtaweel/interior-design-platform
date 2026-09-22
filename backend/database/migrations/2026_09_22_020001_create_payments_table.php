<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Unlike payment_schedules (indirect, three-hop), this table carries organization_id AND
     * project_id directly — per the ERD's own choice (PROJECT_CONTEXT.md Sprint 6), not a
     * convention deviation: payments must be queryable project-wide independent of which
     * contract/schedule they're against (e.g. "all payments for this project" without joining
     * through payment_schedules -> contracts). See App\Models\Payment, which uses
     * BelongsToOrganization directly (like Client/Project) rather than an
     * auditOrganizationId() override.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // restrictOnDelete (not cascade/null): a payment record must never be silently
            // orphaned — deleting a payment_schedules row while payments exist against it is
            // blocked at the DB level, same "financial record must not vanish" discipline as
            // contracts.proposal_version_id.
            $table->foreignId('payment_schedule_id')->constrained('payment_schedules')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            // e.g. bank_transfer | cash | cheque — plain string, no DB enum, matching the
            // established convention (projects.status, contracts.status, etc.).
            $table->string('payment_method');
            $table->timestamp('paid_at');
            // Free-text bank/cheque reference number, optional.
            $table->string('reference')->nullable();
            // Storage path (not a public URL) under local disk, e.g.
            // "receipts/{organization_id}/{payment_id}.{ext}" — set by backend-api-engineer
            // after the file is stored via Storage::disk('local'). Never a raw public
            // /storage/... path; access is brokered through GET /payments/{id}/receipt.
            $table->string('receipt_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            // PROJECT_CONTEXT.md "Recommended indexes": payments(project_id, paid_at) — backs
            // both the per-project payment history/receipts views and the `collected` sum in
            // GET /projects/{id}/financials.
            $table->index(['project_id', 'paid_at']);
            $table->index('payment_schedule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
