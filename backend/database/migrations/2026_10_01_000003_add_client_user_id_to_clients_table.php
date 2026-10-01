<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an org-scoped `Client` contact record to a global marketplace `ClientUser` login. One
 * `ClientUser` can link to many `Client` rows across many organizations (one per org they've
 * engaged with via the marketplace) — but at most one `Client` row per organization per
 * `ClientUser`, enforced by the composite unique index below. Postgres unique indexes ignore
 * NULL values, so this never blocks the existing staff-created-with-no-marketplace-link case
 * (client_user_id null) from having as many rows as it likes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('client_user_id')->nullable()->after('organization_id')
                ->constrained('client_users')->nullOnDelete();

            $table->unique(['organization_id', 'client_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'client_user_id']);
            $table->dropConstrainedForeignId('client_user_id');
        });
    }
};
