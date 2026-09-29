<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD S17 "Site Report: Work done, issues, decisions, photos, PDF." Photos via Spatie Media
 * Library (SiteReport implements HasMedia), same as ProjectTask.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // restrictOnDelete (not nullOnDelete like ProjectTask.assignee_user_id): a site
            // report is a signed record of who reported it — that attribution must never
            // silently disappear the way an unassigned task can.
            $table->foreignId('reported_by_user_id')->constrained('users')->restrictOnDelete();
            $table->date('report_date');
            $table->text('work_done');
            $table->text('issues')->nullable();
            $table->text('decisions')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('report_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_reports');
    }
};
