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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // restrictOnDelete (not cascade): a property is a commercial record of record for
            // its projects; deleting a client that still owns properties should be an explicit,
            // conscious action rather than something that silently cascades further into
            // projects. Caller must reassign/delete properties first.
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // e.g. apartment | villa | office | retail | other
            $table->string('type');
            $table->string('compound')->nullable();
            $table->text('address')->nullable();
            // Precise measurement — numeric, never float.
            $table->decimal('area_m2', 10, 2)->nullable();
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->unsignedSmallInteger('bathrooms')->nullable();
            $table->jsonb('metadata_json')->default('{}');
            $table->timestamps();

            $table->index('organization_id');
            $table->index('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
