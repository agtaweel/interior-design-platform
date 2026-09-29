<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD "CRM/Leads: conversion to client/project" — converting a lead creates a real Client (and
 * optionally a Project) rather than just flipping status; these columns are the auditable link
 * back to what was created, so a converted lead's history is never lost (BRD S03: "Move lead
 * through statuses and convert without data loss").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('converted_client_id')->nullable()->after('owner_id')
                ->constrained('clients')->nullOnDelete();
            $table->foreignId('converted_project_id')->nullable()->after('converted_client_id')
                ->constrained('projects')->nullOnDelete();
            $table->timestamp('converted_at')->nullable()->after('converted_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_client_id');
            $table->dropConstrainedForeignId('converted_project_id');
            $table->dropColumn('converted_at');
        });
    }
};
