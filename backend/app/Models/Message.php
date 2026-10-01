<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BRD v4 "Client Marketplace" — one chat message within a Conversation. No own
 * `organization_id`; scoped indirectly through `conversation` (same convention as
 * ProposalItem/BoqItem). See the migration's docblock for why `sender_type`/`sender_id` are
 * plain columns rather than a polymorphic relation.
 */
#[Fillable(['conversation_id', 'sender_type', 'sender_id', 'body', 'read_at'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
