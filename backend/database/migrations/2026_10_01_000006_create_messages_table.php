<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD v4 "Client Marketplace" — one chat message within a Conversation. No own `organization_id`
 * (scoped indirectly through `conversation`, same convention as ProposalItem/BoqItem — see
 * those models' docblocks). `sender_type` + `sender_id` identify who sent it without a
 * polymorphic Eloquent relation: a message's sender is always either the conversation's own
 * `client_user_id` (sender_type='client') or some staff `User` belonging to the conversation's
 * organization (sender_type='org_member') — Message itself doesn't need to load that relation,
 * only the two controllers that already know which side they're on when creating a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('sender_type');
            $table->unsignedBigInteger('sender_id');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
