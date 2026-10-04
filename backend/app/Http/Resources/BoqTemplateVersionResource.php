<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** `items` is only included when the `items` relation is eager-loaded (the version detail
 *  endpoint); the versions-list endpoint omits it to stay lightweight. */
class BoqTemplateVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->template_id,
            'version_number' => $this->version_number,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'change_notes' => $this->change_notes,
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => BoqTemplateItemResource::collection($this->items)->resolve()),
        ];
    }
}
