<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — alternate names for a catalog item, used only for
 * search/matching (e.g. the catalog picker's search box, future CSV-import item matching).
 * Never load-bearing for template/apply logic — a template item always references
 * boq_catalog_items.id directly, never an alias row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_catalog_item_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('boq_catalog_items')->cascadeOnDelete();
            $table->string('alias_en')->nullable();
            $table->string('alias_ar')->nullable();
            $table->timestamps();

            $table->index('catalog_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_catalog_item_aliases');
    }
};
