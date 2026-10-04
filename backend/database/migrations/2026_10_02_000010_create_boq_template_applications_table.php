<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — one row per "a template version was applied to a
 * project" event (written by App\Services\Boq\BoqTemplateCommitService, Phase 3). This is the
 * auditability snapshot the product spec asks for ("where did this BOQ item come from" at the
 * application level, not just the per-item source_* columns on boq_items) and doubles as the
 * source for template usage-count/conversion analytics (COUNT(*) WHERE template_id = X) without
 * needing a dedicated analytics table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_template_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('boq_templates')->nullOnDelete();
            $table->foreignId('template_version_id')->nullable()->constrained('boq_template_versions')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamps();

            $table->index('template_id');
            $table->index('project_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_template_applications');
    }
};
