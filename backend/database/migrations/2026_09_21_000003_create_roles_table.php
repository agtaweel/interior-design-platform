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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // Nullable so we can ship global template roles (Owner, Admin, Designer,
            // Site Staff, ...) that every organization starts with, alongside
            // organization-specific custom roles (organization_id set).
            $table->foreignId('organization_id')->nullable()
                ->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            // RBAC permission map, e.g. {"clients.view": true, "payments.record": false}.
            $table->jsonb('permissions_json')->default('{}');
            $table->timestamps();

            $table->index('organization_id');
            // NOTE: Postgres treats NULLs as distinct, so this unique index does not stop
            // two global (organization_id IS NULL) roles from sharing a name — acceptable
            // for MVP since global roles are seeded/managed by us, not end users.
            $table->unique(['organization_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
