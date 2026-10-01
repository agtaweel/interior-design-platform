<?php

namespace App\Services\Chat;

use App\Models\ClientUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;

/**
 * BRD v4 "Client Marketplace" — the single entry point for starting a conversation and posting
 * a message into one, so "one thread per client+org pair" (see Conversation's migration
 * docblock) is enforced in exactly one place rather than re-implemented by both
 * ClientConversationController and OrganizationInquiryController.
 */
class ConversationService
{
    public function startConversation(ClientUser $clientUser, Organization $organization): Conversation
    {
        // No tenant context exists on client.user routes (they run outside the `tenant`
        // middleware — see EnsureClientUser's docblock), so OrganizationScope/
        // BelongsToOrganization's saving guard are both no-ops here; this is a plain
        // firstOrCreate, not one that needs to bypass tenant scoping.
        return Conversation::firstOrCreate(
            ['organization_id' => $organization->id, 'client_user_id' => $clientUser->id],
            ['status' => 'open'],
        );
    }

    public function postMessage(Conversation $conversation, string $senderType, int $senderId, string $body): Message
    {
        return $conversation->messages()->create([
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'body' => $body,
        ]);
    }
}
