<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No organization_id column here by design. Unlike boq_items/pricing_rules (one hop:
     * project_id -> projects.organization_id), this table is scoped indirectly through TWO
     * hops: proposal_version_id -> proposal_versions.project_id -> projects.organization_id.
     * See App\Models\ProposalItem's docblock for how this is resolved for auditing/tenancy
     * purposes.
     */
    public function up(): void
    {
        Schema::create('proposal_items', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: a proposal item is meaningless without its parent version —
            // deleting the version removes its frozen line items with it.
            $table->foreignId('proposal_version_id')->constrained('proposal_versions')->cascadeOnDelete();
            // nullOnDelete, not cascade: traceability only. This is a frozen copy made at
            // proposal-version-creation time — never re-read from boq_items afterward. If the
            // source BOQ item is later archived/deleted, the frozen proposal item (part of a
            // possibly already-sent/approved commercial document) must NOT disappear with it;
            // it just loses its traceability pointer. Nullable because a future manually-added
            // proposal line with no BOQ source should also be possible.
            $table->foreignId('source_boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            // Client-facing shape only (matches what S11/the public endpoint shows) — NEVER
            // add cost/margin fields here, unlike boq_items which carries internal cost columns.
            $table->text('description');
            $table->decimal('quantity', 10, 2);
            $table->string('unit');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index('proposal_version_id');
            $table->index('source_boq_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposal_items');
    }
};
