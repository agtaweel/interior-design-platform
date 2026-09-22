<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No organization_id column here by design (same rationale as boq_categories/boq_items/
     * rooms): this table is scoped indirectly through project_id -> projects.organization_id.
     *
     * Enum-style columns (`type`, `method`, `base_selector`) are plain strings, not a Postgres
     * native ENUM or CHECK constraint — this follows the exact convention already established
     * by `projects.status` (see 2026_09_21_000007_create_projects_table.php): document the
     * allowed values in a migration/model comment, validate them at the application layer
     * (backend-api-engineer's controllers, e.g. Rule::in()), and keep the column a bare
     * `string` so adding a new value later is a no-op migration-wise. A CHECK constraint would
     * be marginally safer against writes that bypass the app layer, but this codebase has no
     * precedent for that pattern anywhere else, and introducing one only for this table would
     * be an inconsistency, not an improvement.
     */
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: pricing rules are structural/commercial children of a project —
            // deleting the project removes its rule set too (same convention as boq_categories/
            // boq_items/rooms).
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            // markup | fee | discount. "Supervision" is just a fee with that name — the base
            // stays configurable per PROJECT_CONTEXT.md's locked product decision rather than
            // hardcoding what supervision is computed on.
            $table->string('type');
            // percentage | fixed_amount
            $table->string('method');
            // The rule's percentage (e.g. 15.00 meaning 15%) or fixed EGP amount (e.g.
            // 15000.00). decimal(12,2): matches the precision already used for every other
            // money column in this schema (boq_items.material_unit_cost et al.) — 2 decimal
            // places comfortably covers EGP amounts (piastres) and the percentage values the
            // PRD's examples use (12.50, 15.00), and reusing the established precision keeps
            // arithmetic between this column and boq_items' cost columns exact under bcmath
            // (mixing differing scales would force rounding decisions that don't belong in a
            // schema-only migration). If a future requirement needs fractional-percentage
            // precision beyond 2 decimal places (e.g. 12.375%), widen the scale in a follow-up
            // migration rather than guessing at it now.
            $table->decimal('value', 12, 2);
            // boq_direct_cost | boq_client_subtotal | running_subtotal — see
            // docs/PROJECT_CONTEXT.md "Sprint 3 scope" for the full semantics of each.
            $table->string('base_selector');
            // Recalculation applies active rules in this order; also this table's declared
            // index below.
            $table->unsignedInteger('sort_order')->default(0);
            // Inactive rules are ignored by recalculation but kept (not deleted) so rule
            // history stays visible/re-enable-able.
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('project_id');
            // Recalculation always reads all active rules for a project in sort_order —
            // exact composite requested by PROJECT_CONTEXT.md's Sprint 3 scope.
            $table->index(['project_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
