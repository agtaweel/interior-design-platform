<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD v3 §7 "Invoice / Receipt Vault": the source-of-record for every supplier
 * invoice/receipt backing a financial_transactions entry (see that table's
 * source_document_id). Created before financial_transactions since that table references
 * this one.
 *
 * Immutable after posting (BRD: "Original document is immutable after posting; corrections
 * create new version/event") — supersedes_id links a correcting upload to the row it replaces,
 * rather than ever updating amount/invoice_number/file in place. Duplicate detection (BRD:
 * "vendor/invoice/amount/date and document fingerprint where practical") is enforced in
 * InvoiceController::store(), not at the DB level, since it's a soft business rule
 * (fingerprint collisions across genuinely different documents are possible, however
 * unlikely) rather than a hard uniqueness constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('vat_amount', 14, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            // Optional room/BOQ-item attribution (BRD: "project, room/BOQ item and payment
            // status") — nullable, not every invoice maps to a single BOQ line.
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            // unpaid | paid | partial — a display/tracking aid; the authoritative
            // paid-vs-outstanding number is always the ledger, never this column (BRD §27
            // "One source of truth ... no screen may invent a financial truth the ledger
            // can't explain").
            $table->string('payment_status')->default('unpaid');
            // SHA-256 of the uploaded file bytes — the "document fingerprint" half of
            // duplicate detection (the other half is the vendor/invoice/amount/date tuple,
            // checked in InvoiceController::store()).
            $table->string('file_fingerprint', 64);
            $table->foreignId('supersedes_id')->nullable()->constrained('invoice_documents')->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('organization_id');
            $table->index('project_id');
            $table->index(['organization_id', 'file_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_documents');
    }
};
