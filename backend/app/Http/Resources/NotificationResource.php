<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /notifications — a user's own in-app notification inbox (PROJECT_CONTEXT.md Sprint 8).
 * No cost/margin concern here (notifications carry only `payload_json`, which the trigger points
 * populate with project id/name, entity id/number, and a human summary — never internal cost
 * fields), so this is the only serialization this endpoint needs.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'type' => $this->type,
            'payload' => $this->payload_json,
            'sent_at' => $this->sent_at,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
