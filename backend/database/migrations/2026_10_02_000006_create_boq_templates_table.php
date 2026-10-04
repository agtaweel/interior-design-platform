<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — the template HEADER: a named, versioned,
 * categorized "what normally goes together" definition (Full Apartment Finishing — Standard,
 * Master Bedroom room template, Smart Home package — rooms and packages reuse this exact same
 * table via `template_type`, not separate tables, per the product spec's own "packages should
 * use the same template system" instruction). `active_version_id` (added in a follow-up
 * migration to resolve the circular FK with boq_template_versions) tracks which published
 * version new project applications use by default; an already-applied project's
 * `source_template_version_id` (added to boq_items in Phase 3) stays pinned to whatever version
 * it actually used, even after a newer version becomes active.
 *
 * `organization_id` nullable via BelongsToOrganization: null = system template (platform-owned,
 * ships with the app, visible to every organization), non-null = that organization's own
 * private template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            // FULL_FINISHING|RENOVATION|PARTIAL_FINISHING|ROOM|TRADE|PACKAGE|PREMIUM|LUXURY|CUSTOM
            $table->string('template_type');
            $table->string('project_type')->nullable();
            // BASIC|STANDARD|PREMIUM|LUXURY
            $table->string('finishing_level')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Postgres treats NULL as distinct from NULL in a unique index, so this only
            // actually constrains non-null organization_id pairs (same quirk as
            // clients.unique(organization_id, client_user_id)) — system (org_id=null) template
            // codes are kept unique by seeder discipline, not a DB constraint.
            $table->unique(['organization_id', 'code']);
            $table->index('organization_id');
            $table->index('template_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_templates');
    }
};
