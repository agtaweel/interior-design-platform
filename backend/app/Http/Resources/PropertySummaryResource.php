<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal property payload for embedding inside the ProjectResource dashboard payload.
 */
class PropertySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'compound' => $this->compound,
            'address' => $this->address,
        ];
    }
}
