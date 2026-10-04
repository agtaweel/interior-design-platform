<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — a template line item: references a Master Catalog
 * item (never duplicates its name/description — see BoqCatalogItem) plus the template-specific
 * data layered on top (is this item required or optional, what quantity/formula/source applies,
 * optional per-template cost overrides). Belongs to a specific `template_version_id`, not
 * directly to a template — see boq_template_versions' migration docblock for why.
 *
 * `category_id` is a denormalized snapshot of the catalog item's category AT AUTHORING TIME —
 * deliberately not re-derived or kept in sync if the catalog item is later recategorized, same
 * "frozen at the moment it was built" reasoning as a sent proposal's snapshot_json. A template
 * keeps the grouping it had when authored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_version_id')->constrained('boq_template_versions')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained('boq_catalog_items')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('boq_catalog_categories')->restrictOnDelete();
            $table->foreignId('default_unit_id')->nullable()->constrained('boq_units')->nullOnDelete();
            $table->decimal('default_quantity', 10, 2)->nullable();
            $table->string('quantity_formula')->nullable();
            // FIXED_DEFAULT|FORMULA|USER_INPUT|OPTIONAL
            $table->string('quantity_source')->default('FIXED_DEFAULT');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_enabled_by_default')->default(true);
            $table->decimal('material_unit_cost', 12, 2)->nullable();
            $table->decimal('labor_unit_cost', 12, 2)->nullable();
            $table->decimal('other_unit_cost', 12, 2)->nullable();
            $table->decimal('client_unit_price', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('template_version_id');
            $table->index('catalog_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_template_items');
    }
};
