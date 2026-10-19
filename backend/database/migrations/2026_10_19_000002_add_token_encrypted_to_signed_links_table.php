<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/SIMPLIFICATION_REVIEW.md P3.1 — the client portal link becomes the one link a client
 * ever gets, so staff must be able to copy it again (and the send flow must be able to put it in
 * every WhatsApp message) instead of it being shown once at creation. Only links issued as
 * "retrievable" (today: purpose client_portal) store the raw token here, encrypted with the app
 * key (Eloquent `encrypted` cast); one-time approval links keep hash-only storage. A database
 * dump alone still yields no usable link — it also takes APP_KEY, which lives outside the DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signed_links', function (Blueprint $table) {
            $table->text('token_encrypted')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('signed_links', function (Blueprint $table) {
            $table->dropColumn('token_encrypted');
        });
    }
};
