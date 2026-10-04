<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — final step of the replacement: drop the renamed
 * legacy tables now that migrate_legacy_boq_templates_data has transcribed every organization's
 * data into the new model. Runs in the same deploy as every other migration in this feature, so
 * there is no window where both the old and new template systems coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('boq_template_items_legacy');
        Schema::dropIfExists('boq_template_categories_legacy');
    }

    public function down(): void
    {
        // Irreversible alongside migrate_legacy_boq_templates_data's own down() — see that
        // migration's docblock.
    }
};
