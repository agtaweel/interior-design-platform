<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD v3 §12 "Reconciliation & Closeout": a state machine deliberately kept SEPARATE from
 * `projects.status` (the pre-existing operational lifecycle column — draft/active/completed,
 * gated by mandatory-snag checks in ProjectController::update()). `financial_status` tracks
 * whether the project's BOOKS are settled, which is an independent concern from whether the
 * physical work is done — a project can be operationally 'completed' (handed over) while its
 * financial reconciliation is still pending, and closing the books is never itself a trigger
 * for (or triggered by) a snag/handover check.
 *
 * States: active -> financial_pending -> ready_for_close -> closed, plus the force_closed
 * escape hatch (ProjectCloseoutService::forceClose()) that can jump directly to 'closed' from
 * any non-closed state, bypassing the normal "outstanding must be 0" gate — always paired with
 * a mandatory force_close_reason, per the BRD's own explicit requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('financial_status')->default('active')->after('status');
            $table->timestamp('financial_closed_at')->nullable();
            $table->foreignId('financial_closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('force_closed')->default(false);
            $table->text('force_close_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_closed_by');
            $table->dropColumn(['financial_status', 'financial_closed_at', 'force_closed', 'force_close_reason']);
        });
    }
};
