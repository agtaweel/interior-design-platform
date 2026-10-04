<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — purely-additive source tracking for a project BOQ
 * item created via the template preview/commit flow. All four columns are nullable (a manually
 * added BoqItem has none of them) and nullOnDelete (deleting a template/version/catalog item
 * later must never cascade-delete a project's already-committed BOQ item — the project's copy
 * stays fully independent once created, per the plan's "Project BOQ remains the sole financial
 * source of truth" decision). No source_package_id: a "package" is just a BoqTemplate row with
 * template_type = PACKAGE, so source_template_id already answers that via a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->foreignId('source_template_id')->nullable()->after('archived_at')->constrained('boq_templates')->nullOnDelete();
            $table->foreignId('source_template_version_id')->nullable()->after('source_template_id')->constrained('boq_template_versions')->nullOnDelete();
            $table->foreignId('source_template_item_id')->nullable()->after('source_template_version_id')->constrained('boq_template_items')->nullOnDelete();
            $table->foreignId('source_catalog_item_id')->nullable()->after('source_template_item_id')->constrained('boq_catalog_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_template_id');
            $table->dropConstrainedForeignId('source_template_version_id');
            $table->dropConstrainedForeignId('source_template_item_id');
            $table->dropConstrainedForeignId('source_catalog_item_id');
        });
    }
};
