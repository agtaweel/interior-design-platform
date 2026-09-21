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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            // ISO 4217 currency code. EGP is the product default per PROJECT_CONTEXT.md.
            $table->string('currency', 3)->default('EGP');
            $table->string('timezone')->default('Africa/Cairo');
            // Flexible per-organization settings (keeps multi-branch/multi-brand open later
            // per locked decision #6) — internal markup layers, branding, feature flags, etc.
            $table->jsonb('settings_json')->default('{}');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
