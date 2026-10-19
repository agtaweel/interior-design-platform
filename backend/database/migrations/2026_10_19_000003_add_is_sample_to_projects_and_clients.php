<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/SIMPLIFICATION_REVIEW.md P4.3 — a one-click sample project a new company can click around
 * before entering real data (SampleProjectService). Flagged so it can be labelled in the UI,
 * kept out of money KPIs/reports and the getting-started checklist, and removed in one click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_sample')->default(false)->after('status');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_sample')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('is_sample');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('is_sample');
        });
    }
};
