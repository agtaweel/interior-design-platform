<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PROJECT_CONTEXT.md's Sprint 7 lifecycle description says the Send step "sets sent_at",
     * but the original schema field list in that same section omitted the column — an
     * oversight caught during implementation (db-architect correctly flagged it rather than
     * silently adding or silently skipping it). Added here, matching proposal_versions.sent_at
     * exactly (nullable timestamp, set once at Send, never touched again).
     */
    public function up(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('timeline_delta_days');
        });
    }

    public function down(): void
    {
        Schema::table('change_orders', function (Blueprint $table) {
            $table->dropColumn('sent_at');
        });
    }
};
