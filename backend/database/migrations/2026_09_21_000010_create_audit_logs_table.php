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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: audit logs are only meaningful in the context of their
            // organization; if an organization is ever hard-deleted (rare — full tenant
            // teardown), its audit trail goes with it rather than becoming orphaned rows.
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // nullOnDelete: preserve the audit record even if the acting user is later
            // deleted — losing *who* did it is worse than losing the FK.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Polymorphic-by-convention (not an Eloquent morph FK, since entity_type spans
            // many tables): e.g. "Project", "ProposalVersion", "Payment".
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            // e.g. created | updated | deleted | approved | status_changed
            $table->string('action');
            $table->jsonb('before_json')->nullable();
            $table->jsonb('after_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            // Audit rows are append-only/immutable: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('organization_id');
            $table->index(['organization_id', 'entity_type', 'entity_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
