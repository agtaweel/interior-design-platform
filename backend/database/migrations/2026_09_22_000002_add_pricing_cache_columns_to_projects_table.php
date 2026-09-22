<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds cache columns directly to `projects` rather than a separate one-row-per-project
     * cache table, per PROJECT_CONTEXT.md's Sprint 3 scope stated preference. A separate table
     * would only pay for itself if these columns needed independent versioning/history or a
     * different write-concurrency profile than the rest of the `projects` row — neither is
     * true here: they're recomputed wholesale by a single explicit recalculate action and read
     * alongside every other project field (ProjectResource.financials), so keeping them
     * co-located avoids an extra join on every dashboard/project-overview read.
     *
     * All six money columns are nullable with NO default (not even 0) — 0 is a valid computed
     * total (e.g. a project with no BOQ items yet, or a 100% discount), so it cannot double as
     * "never priced". Only an explicit recalculation run populates these; until then they must
     * read as null so the frontend can distinguish "priced at zero" from "never priced" (per
     * PROJECT_CONTEXT.md's Sprint 3 instruction, restated here so the distinction isn't lost on
     * a future migration edit).
     *
     * decimal(14, 2), not the (12, 2) used for boq_items' per-unit/per-line money columns:
     * these are project-wide sums across every BOQ line item (potentially hundreds), so the
     * integer part needs more headroom than a single line item ever would, while the 2-decimal
     * scale stays consistent with every other money column in the schema.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Sum of non-archived boq_items.direct_cost as of the last recalculation.
            $table->decimal('direct_cost_total', 14, 2)->nullable()->default(null);
            // Sum of non-archived boq_items.client_total as of the last recalculation — the
            // fixed anchor pricing_rules with base_selector=boq_client_subtotal resolve against.
            $table->decimal('client_subtotal', 14, 2)->nullable()->default(null);
            // Accumulated contribution of active type=markup rules.
            $table->decimal('markup_total', 14, 2)->nullable()->default(null);
            // Accumulated contribution of active type=fee rules.
            $table->decimal('fees_total', 14, 2)->nullable()->default(null);
            // Accumulated contribution of active type=discount rules.
            $table->decimal('discount_total', 14, 2)->nullable()->default(null);
            // Final running_subtotal after all active rules apply, in sort_order — the
            // project's client-facing price. This is what ProjectResource.financials.value
            // will read from once backend-api-engineer wires it up (left untouched here, still
            // hardcoded 0, per this task's scope).
            $table->decimal('grand_total', 14, 2)->nullable()->default(null);
            // When the cache columns above were last (re)computed. Null alongside them means
            // "never priced yet".
            $table->timestamp('priced_at')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'direct_cost_total',
                'client_subtotal',
                'markup_total',
                'fees_total',
                'discount_total',
                'grand_total',
                'priced_at',
            ]);
        });
    }
};
