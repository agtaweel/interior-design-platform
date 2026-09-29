<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD S20 "Handover: Final approval, warranty, completion document." One handover per project
 * (unique project_id) — the completion document itself is a generated PDF
 * (HandoverController::pdf()), not a separately uploaded file, mirroring Contract/SiteReport's
 * own PDF-on-demand convention rather than introducing a new upload mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->date('handover_date');
            $table->unsignedInteger('warranty_period_months')->nullable();
            $table->text('warranty_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handovers');
    }
};
