<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — a small, global (not organization-scoped) reference
 * table of standardized unit codes (m2, lm, pcs, point, ...), bilingual. Used as a Master Catalog
 * item's *default* unit only — the actual applied Project BOQ item's `boq_items.unit` column
 * stays a plain string (unchanged, snapshotted at apply-time), so this table never becomes a
 * breaking FK on the already-shipped boq_items schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_units', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_units');
    }
};
