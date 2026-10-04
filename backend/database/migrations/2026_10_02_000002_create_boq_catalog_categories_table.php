<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ Master Catalog + Standard Templates — the Master Catalog's category tree. Self-referential
 * `parent_id` (unlimited depth), the same pattern `boq_categories`/`boq_template_categories`
 * already use — a "subcategory" per the product spec is simply a category row with a non-null
 * `parent_id`, not a separate table.
 *
 * `organization_id` nullable: null = system/global category (platform-owned, visible to every
 * organization), non-null = that organization's own private custom-catalog category. This reuses
 * App\Models\Concerns\BelongsToOrganization's existing "organization_id IS NULL is a global row"
 * scope behavior verbatim (the same mechanism global template Roles already rely on) — no new
 * scoping logic needed anywhere in the app for catalog isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('boq_catalog_categories')->nullOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['organization_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_catalog_categories');
    }
};
