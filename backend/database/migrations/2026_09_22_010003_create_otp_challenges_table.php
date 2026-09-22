<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * No organization scoping at all (not even indirect) — this table is reached only via
     * signed_link_id -> the associated entity's tenant, and is only ever touched by
     * token-authenticated public-endpoint code (App\Support\PublicLinks\SignedLinkService
     * callers), never by tenant-scoped internal queries. Same rationale as `signed_links`
     * itself having no organization_id.
     *
     * Generic by design, not proposal-specific: Sprint 7's change-order public approval will
     * reuse this identical mechanism against its own signed_links row, per
     * PROJECT_CONTEXT.md's Sprint 4 scope.
     */
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: an OTP challenge is meaningless without the signed link it
            // gates — if the link is ever hard-deleted, its challenge goes with it.
            $table->foreignId('signed_link_id')->constrained('signed_links')->cascadeOnDelete();
            // Never store the raw OTP code — only a hash, same principle as
            // signed_links.token_hash.
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            // Incremented on each failed verification attempt; the verifying code (backend-
            // api-engineer) caps and locks out after e.g. 5 failures rather than allowing brute
            // force, per PROJECT_CONTEXT.md's Sprint 4 scope.
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index('signed_link_id');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
