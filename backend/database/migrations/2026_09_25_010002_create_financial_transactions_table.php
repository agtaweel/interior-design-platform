<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD v3 §4/§6 "Strict Financial Ledger — Core Differentiator": the single authoritative
 * source every client-facing and internal financial figure derives from (BRD §27 "One source
 * of truth ... platform analytics read from the same authoritative records"). Every operation
 * that used to just write a number onto Payment.amount/Contract.contract_value now ALSO posts
 * one of these rows — see FinancialLedgerService for the read side (client_obligation/
 * client_outstanding/actual_project_cost/gross_profit) and PaymentRecordingService/
 * ContractController/ChangeOrderApplyService/ExpenseRecordingService/PurchaseOrderController
 * for the write side.
 *
 * `amount` is a positive magnitude for every type EXCEPT `change_order_charge` and `adjustment`,
 * which carry their own natural real-world sign (a change order can just as easily reduce scope
 * as add to it, and an adjustment is by definition a correction in either direction — neither
 * has a single fixed "this type always increases/decreases" direction the way e.g.
 * `client_payment` always reduces outstanding). This mirrors how `ChangeOrder.price_delta`
 * already works elsewhere in this codebase, and is the exact rule FinancialLedgerService's
 * summary formula depends on (verified against the BRD's own Golden Financial Test Case). A
 * reversal is a full mirror-image row (same signed amount, same type, linked via
 * reversal_of_id) plus a status flip on the original — never an in-place edit of the original's
 * amount/date/source, per BRD's "posted amount/date/source cannot be overwritten" rule.
 *
 * `scope` separates the two ledgers this system must never blend (BRD §6 "Separate client
 * receivable from project cost and profit"): 'client' entries (contract_charge,
 * change_order_charge, client_payment, refund, credit) feed client_obligation/
 * client_outstanding; 'cost' entries (supplier_invoice, expense, contractor_cost) feed
 * actual_project_cost. 'adjustment'/'reversal' can carry either scope, inherited from
 * whatever they're correcting.
 *
 * No direct organization_id/project_id FK away from being nullable — both are always required
 * and denormalized here (like Payment) for cheap tenant-scoped querying of what will become a
 * high-volume table, not derived indirectly through source_entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // client | cost — see class docblock.
            $table->string('scope');
            // contract_charge | change_order_charge | client_payment | refund | credit |
            // supplier_invoice | expense | contractor_cost | adjustment | reversal
            $table->string('type');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('EGP');
            $table->date('transaction_date');
            // draft | posted | reversed — this MVP's application code only ever writes rows
            // directly as 'posted' (no draft-then-review workflow yet); 'reversed' is the only
            // in-place status transition ever applied to an existing row, and only by
            // FinancialLedgerService::reverse() (see that method — it also inserts a new
            // mirror-image 'reversal' row rather than just flipping this flag alone).
            $table->string('status')->default('posted');
            // Polymorphic reference to whatever business row caused this entry (Payment,
            // Contract, ChangeOrder, ProjectExpense, PurchaseOrderItem, ...) — traceability
            // only, per BRD "every client-facing financial number must trace to a ledger/
            // source record."
            $table->string('source_entity_type')->nullable();
            $table->unsignedBigInteger('source_entity_id')->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('invoice_documents')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['project_id', 'scope']);
            $table->index(['project_id', 'type']);
            $table->index(['source_entity_type', 'source_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
