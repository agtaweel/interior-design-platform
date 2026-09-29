<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD "Procurement & Supplier Intelligence": supplier directory with categories, contacts and
 * payment terms. Organization-scoped (not project-scoped) — a supplier is reused across every
 * project, matching BRD "Allow multiple suppliers for the same category/item."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            // Free-text category (e.g. "Flooring", "Electrical") — deliberately not a foreign
            // key to boq_categories: a supplier's category is a directory-classification label
            // independent of any one project's BOQ structure, and a supplier can span several.
            $table->string('category')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['organization_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
