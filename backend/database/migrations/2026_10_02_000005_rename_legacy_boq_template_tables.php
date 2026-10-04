<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — the old flat template system
 * (boq_template_categories/boq_template_items) is being replaced by the new Master Catalog +
 * versioned Templates architecture, which needs the canonical table name `boq_template_items`
 * for its own, differently-shaped table. Renaming (not dropping) the legacy tables first lets a
 * later migration (migrate_legacy_boq_templates_data) read every organization's existing
 * template data and transcribe it into the new model before the legacy tables are finally
 * dropped (drop_legacy_boq_template_tables) — zero data loss across the replacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('boq_template_categories', 'boq_template_categories_legacy');
        Schema::rename('boq_template_items', 'boq_template_items_legacy');
    }

    public function down(): void
    {
        Schema::rename('boq_template_items_legacy', 'boq_template_items');
        Schema::rename('boq_template_categories_legacy', 'boq_template_categories');
    }
};
