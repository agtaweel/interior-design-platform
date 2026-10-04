<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — a leaf Master Catalog item: defines WHAT a line of
 * work is (name, description, default unit), never a project-specific price. The
 * default_*_cost columns are optional, non-binding STARTING SUGGESTIONS only (same posture as
 * BoqTemplateItem's cost columns today) — the Project BOQ item remains the sole financial
 * source of truth regardless of what a catalog item or template suggests.
 *
 * organization_id nullable: null = global/system catalog item, non-null = an organization's own
 * private custom-catalog item — see boq_catalog_categories' migration docblock for the reused
 * BelongsToOrganization isolation mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('boq_catalog_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->foreignId('default_unit_id')->nullable()->constrained('boq_units')->nullOnDelete();
            $table->decimal('default_material_unit_cost', 12, 2)->nullable();
            $table->decimal('default_labor_unit_cost', 12, 2)->nullable();
            $table->decimal('default_other_unit_cost', 12, 2)->nullable();
            $table->decimal('default_client_unit_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('organization_id');
            $table->index('category_id');
            $table->index(['organization_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_catalog_items');
    }
};
