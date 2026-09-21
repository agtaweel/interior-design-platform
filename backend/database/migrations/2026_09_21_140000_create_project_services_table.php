<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No organization_id column here by design (same rationale as project_members): this
     * table is scoped indirectly through project_id -> projects.organization_id. See the
     * ProjectService model docblock for how Auditable resolves the audit organization_id
     * without a direct column.
     */
    public function up(): void
    {
        Schema::create('project_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // e.g. design | execution | supervision | consultation | other — free-form string
            // per the ERD field list; no fixed enum locked yet.
            $table->string('service_type');
            // How `price` should be interpreted: fixed | per_m2 | per_room | percentage.
            $table->string('pricing_method');
            // Precise money value — numeric, never float, per PROJECT_CONTEXT.md money rules.
            $table->decimal('price', 14, 2);
            $table->jsonb('metadata_json')->default('{}');
            $table->timestamps();

            $table->index('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_services');
    }
};
