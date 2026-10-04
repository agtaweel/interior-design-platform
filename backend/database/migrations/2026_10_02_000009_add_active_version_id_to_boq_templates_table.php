<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — deferred to its own migration to resolve the
 * circular FK (boq_templates needs boq_template_versions to exist first, and vice versa for
 * boq_template_versions.template_id). Set by App\Services\Boq\BoqTemplateVersionService::publish().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_templates', function (Blueprint $table) {
            $table->foreignId('active_version_id')->nullable()->after('is_active')
                ->constrained('boq_template_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('boq_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_version_id');
        });
    }
};
