<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No organization_id column, and no direct project_id either: this table is scoped
     * indirectly through TWO hops — change_order_id -> change_orders.project_id ->
     * projects.organization_id — mirroring proposal_items' two-hop precedent from Sprint 4
     * exactly (proposal_version_id -> proposal_versions.project_id -> projects.organization_id).
     * See App\Models\ChangeOrderItem's docblock for how this is resolved for auditing/tenancy
     * purposes.
     */
    public function up(): void
    {
        Schema::create('change_order_items', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: a change order item is meaningless without its parent change
            // order — deleting the change order removes its line items with it, same
            // convention as proposal_items -> proposal_versions.
            $table->foreignId('change_order_id')->constrained('change_orders')->cascadeOnDelete();
            // add | remove | modify — plain string, no DB enum/CHECK, matching the established
            // convention (projects.status, change_orders.status).
            $table->string('action');
            // nullOnDelete: null for 'add' (no existing item yet); required for
            // 'remove'/'modify'. Not cascade — a change order item is a commercial record of
            // what was requested/approved and must survive the referenced BOQ item later being
            // archived/deleted, same traceability-only rationale as proposal_items.source_boq_item_id.
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->string('description');
            // Precise measurement — numeric, never float. Precision matches boq_items.quantity.
            $table->decimal('quantity', 10, 2);
            $table->string('unit');
            // Nullable — null for 'add' (there's no prior price to record).
            $table->decimal('old_unit_price', 12, 2)->nullable();
            // Nullable — null for 'remove' (there's no new price, the line is being removed).
            $table->decimal('new_unit_price', 12, 2)->nullable();
            // Computed per action type (application-layer): add -> quantity * new_unit_price
            // (positive); remove -> -(quantity * old_unit_price) (negative); modify ->
            // quantity * (new_unit_price - old_unit_price) (sign follows price direction).
            // Not nullable/no default: always computed at write time, same "compute from
            // formula, don't trust a manual entry" principle as boq_items' cost accessors.
            $table->decimal('line_delta', 12, 2);
            $table->timestamps();

            $table->index('change_order_id');
            $table->index('boq_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('change_order_items');
    }
};
