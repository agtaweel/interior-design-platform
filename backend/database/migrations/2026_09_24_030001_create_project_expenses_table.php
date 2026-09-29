<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD "Expenses: Project expenses, receipts, supplier linkage" — feeds the profitability
 * engine's actual-cost basis (BRD §8: "Actual cost: supplier purchases + labor/contractor cost
 * + project expenses"). project_id (not organization_id directly) is the tenant-scoping
 * anchor, same indirect pattern as Payment (which — unlike PaymentSchedule/Contract — carries
 * BOTH organization_id and project_id directly for cheap querying; ProjectExpense follows
 * Payment's exact precedent here, not the purely-indirect Contract/PurchaseOrder one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // nullOnDelete: losing the supplier record shouldn't delete expense history.
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            // e.g. material | labor | subcontractor | other — free text, not an enum, same
            // rationale as suppliers.category (a fixed enum would fight real-world variety).
            $table->string('category');
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->date('expense_date');
            // Storage path on the private local disk, never a public URL — same discipline as
            // payments.receipt_url (see PaymentController::receipt()'s docblock). Only ever
            // resolved back to bytes through the access-controlled GET /expenses/{id}/receipt
            // route.
            $table->string('receipt_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index('project_id');
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_expenses');
    }
};
