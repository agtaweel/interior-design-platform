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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            // e.g. referral | website | whatsapp | walk_in | social | other
            $table->string('source')->nullable();
            // new | contacted | qualified | converted | lost
            $table->string('status')->default('new');
            $table->decimal('estimated_budget', 14, 2)->nullable();
            $table->text('notes')->nullable();
            // nullOnDelete: losing the assigned sales/design owner shouldn't delete lead history.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('organization_id');
            $table->index('owner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
