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
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // restrictOnDelete: prevent removing a role while members still hold it — force
            // the caller to reassign members first rather than silently orphaning them.
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            // active | invited | suspended
            $table->string('status')->default('invited');
            $table->timestamps();

            $table->index('organization_id');
            $table->index('user_id');
            $table->unique(['organization_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
