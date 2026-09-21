<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // restrictOnDelete per explicit instruction: deleting a client must not silently
            // cascade-delete its projects (a project carries commercial history).
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // nullOnDelete: a property record can be retired/removed without destroying the
            // project's own history; the project simply loses the property reference.
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            // Human-facing project code, unique per organization (e.g. "PRJ-0001").
            $table->string('code');
            $table->string('name');
            // draft | active | on_hold | completed | cancelled
            $table->string('status')->default('draft');
            $table->date('start_date')->nullable();
            $table->date('target_end_date')->nullable();
            // nullOnDelete: if the responsible staff member is removed from `users`, the
            // project must not be blocked or destroyed — it just needs reassignment.
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index('organization_id');
            $table->index(['organization_id', 'status']);
            $table->index('client_id');
            $table->index('property_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
