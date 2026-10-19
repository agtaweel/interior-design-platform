<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/SIMPLIFICATION_REVIEW.md P2.4 — per-organization opt-in: when the client approves a change
 * order, apply it to the BOQ and contract in the same step (ChangeOrderAutoApplier) instead of
 * waiting for staff to press "Apply". Defaults to false so existing organizations keep today's
 * explicit two-step review. A typed column rather than a key in settings_json: settings_json is
 * reserved for the multi-branch escape hatch (PROJECT_CONTEXT.md locked decision #6) and is
 * deliberately not writable through the organization update endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('auto_apply_change_orders')->default(false)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('auto_apply_change_orders');
        });
    }
};
