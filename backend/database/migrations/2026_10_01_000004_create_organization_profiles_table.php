<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public marketplace listing data for an organization — kept as its own 1:1 table rather than
 * columns on `organizations` to separate "public marketing profile a prospective client sees
 * while browsing" from that model's existing internal/billing-style fields (name, legal_name,
 * currency, timezone, settings_json). `is_marketplace_listed` defaults false: an org only
 * appears in marketplace browse results after explicitly opting in (see
 * OrganizationProfileService::setListed()), so existing demo/test organizations never show up
 * unannounced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->json('services_offered')->nullable();
            $table->string('service_area')->nullable();
            $table->boolean('is_marketplace_listed')->default(false);
            $table->timestamps();

            $table->index('is_marketplace_listed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_profiles');
    }
};
