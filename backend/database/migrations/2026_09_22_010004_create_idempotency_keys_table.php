<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Generic idempotency-key store for money-moving/state-changing public POSTs (starting
     * with POST /public/proposals/{token}/approve), per the NFR "idempotency keys required for
     * payment creation and other money-moving POSTs" and PROJECT_CONTEXT.md's Sprint 4
     * reconciliation of that NFR with the PRD's 409-already-approved error example: if a
     * request's `Idempotency-Key` header matches a stored key for that scope, the stored
     * response is replayed verbatim regardless of current state; only new/absent keys hit
     * normal state-conflict rules. No organization scoping — reached only via the scope+key
     * pair, same rationale as otp_challenges/signed_links.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            // e.g. "proposal_approve:{proposal_version_id}" — namespaces the client-supplied
            // key so the same raw key value can't collide across unrelated actions/entities.
            $table->string('scope');
            // The client-supplied `Idempotency-Key` header value.
            $table->string('key');
            $table->unsignedSmallInteger('response_status');
            $table->jsonb('response_body')->nullable();
            // Write-once, append-only: created_at only, no updated_at — a stored response is
            // never mutated after insert, only ever read back for replay.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['scope', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
