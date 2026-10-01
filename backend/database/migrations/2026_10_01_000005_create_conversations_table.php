<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD v4 "Client Marketplace" — a single chat thread between one marketplace ClientUser and one
 * Organization. `unique(organization_id, client_user_id)`: this is deliberately one thread per
 * client+org pair for the life of the relationship (the "simple polling thread" scope decision),
 * not a re-openable ticket system — `status` is informational (open/archived) rather than a gate
 * on whether new messages can be posted. `project_id` is nullable because a conversation starts
 * before any project exists (a prospective client messaging an org they haven't engaged yet);
 * Phase C wires this once staff convert the inquiry into a client + project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('client_users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('status')->default('open');
            $table->timestamps();

            $table->unique(['organization_id', 'client_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
