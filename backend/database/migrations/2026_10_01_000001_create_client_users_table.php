<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace client accounts (BRD v4 "Client Marketplace") — deliberately a SEPARATE table from
 * `users`, not a flag on that model. A `ClientUser` belongs to no organization and never will
 * (see ClientUser model docblock); keeping it a distinct table/model means the tenant-scoping
 * stack (ResolveTenantContext, OrganizationScope, Permissions/Role) never has to reason about a
 * user that doesn't fit its core assumption, the same way `is_platform_owner` sidesteps it with
 * a boolean flag rather than a new table — but a marketplace client additionally needs its own
 * profile fields and its own password-reset table (see create_client_password_reset_tokens),
 * which justifies the extra table where platform-owner's single boolean didn't need one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_users');
    }
};
