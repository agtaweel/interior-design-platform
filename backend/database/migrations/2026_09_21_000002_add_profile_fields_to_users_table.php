<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ERD ambiguity resolved: the PRD's `users` table (name, email, phone, password_hash/
     * auth_provider, status, created_at) overlaps almost entirely with Laravel's scaffolded
     * `users` table (name, email, password, timestamps). Rather than creating a second table,
     * we extend the existing one: Laravel's `password` column already serves as the
     * password_hash field, and we add the remaining PRD-specific columns here.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            // 'local' = password auth via the existing `password` column; future values
            // (e.g. 'google', 'microsoft') support SSO without another migration.
            $table->string('auth_provider')->default('local')->after('password');
            // active | invited | suspended | disabled
            $table->string('status')->default('active')->after('auth_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'auth_provider', 'status']);
        });
    }
};
