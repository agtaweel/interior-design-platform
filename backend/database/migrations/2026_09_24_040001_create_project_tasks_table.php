<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD "Execution: Tasks, milestones, site reports, photos" / S16 "Tasks/Site: Kanban/list,
 * assignee, due date, photos." Photos are handled via the existing Spatie Media Library
 * infrastructure (ProjectTask implements HasMedia with a 'photos' collection), not a new
 * bespoke upload mechanism — see ProjectTask model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // nullOnDelete: losing the assigned user's account shouldn't delete task history.
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            // todo | in_progress | done — a Kanban board's three columns (BRD S16).
            $table->string('status')->default('todo');
            $table->date('due_date')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('project_id');
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
    }
};
