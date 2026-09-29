<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD "Snagging/Handover: defects, priorities, owners, due dates, closure, warranty." Key
 * implementation risk the BRD explicitly calls out: "Project cannot be marked complete with
 * unresolved mandatory snags" — enforced in ProjectController::update() (rejects status
 * 'completed' while an open is_mandatory=true snag exists) and HandoverController::store()
 * (same check, since a handover IS the completion act).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('description');
            // low | medium | high | critical
            $table->string('priority')->default('medium');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            // open | closed
            $table->string('status')->default('open');
            // Non-mandatory snags (cosmetic/minor) don't block project completion — only
            // mandatory ones do, per the BRD rule above.
            $table->boolean('is_mandatory')->default(true);
            $table->text('resolution_notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index(['project_id', 'status', 'is_mandatory']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snags');
    }
};
