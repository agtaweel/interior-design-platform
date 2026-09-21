<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backing store for App\Support\PublicLinks\SignedLinkService — the reusable primitive
     * later sprints use for public, unauthenticated links (e.g. /public/proposals/{token},
     * /public/change-orders/{token}).
     *
     * Deliberately NOT a stateless signed-URL (Laravel's URL::temporarySignedRoute): those
     * embed the route parameters (internal IDs) in plaintext in the URL and can only be
     * revoked wholesale by rotating APP_KEY. The locked product decision requires links that
     * never expose internal IDs and are individually revocable, hence an opaque random token
     * whose hash is looked up here.
     */
    public function up(): void
    {
        Schema::create('signed_links', function (Blueprint $table) {
            $table->id();
            // e.g. "proposal_approval", "change_order_approval" — scopes a token to the one
            // purpose it was issued for, so a token can't be replayed against a different
            // verification call even if the raw token were somehow guessed.
            $table->string('purpose');
            // SHA-256 hex digest of the raw token. The raw token is only ever returned once,
            // to the caller of SignedLinkService::issue(); it is never stored in plaintext, so
            // a database dump does not expose usable links.
            $table->string('token_hash', 64)->unique();
            // Opaque payload identifying what the token grants access to, e.g.
            // {"proposal_version_id": 123, "organization_id": 45}. No internal IDs are placed
            // in the public URL itself — only this opaque token is.
            $table->jsonb('payload_json')->default('{}');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('purpose');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signed_links');
    }
};
