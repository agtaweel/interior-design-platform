<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — each version is a FULL, independent copy of its
 * template's item set (not a diff against the previous version). This is what lets "Full
 * Apartment Standard v2" be published and become the default for new projects while a project
 * that already applied v1 keeps its `source_template_version_id` pointing at v1 forever —
 * editing v2's items can never retroactively change what an already-applied project sees. See
 * App\Services\Boq\BoqTemplateVersionService for the create-draft-as-copy / publish mechanics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('boq_templates')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            // draft|published|archived
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_notes')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_template_versions');
    }
};
