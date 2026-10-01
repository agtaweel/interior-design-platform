<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate from the staff `password_reset_tokens` table (same shape, different table) so a
 * person who is ever both a staff `User` and a marketplace `ClientUser` under the same email
 * can't have a reset token for one account flow be replayed against the other — see
 * ClientPasswordResetService's docblock, which otherwise mirrors PasswordResetService exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_password_reset_tokens');
    }
};
