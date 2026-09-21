<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /clients and GET /clients/{id}. `properties`/`projects` are only present when the
 * controller eager-loaded them (GET /clients/{id}); `*_count` are only present when the
 * controller used withCount (both index and show) — see ClientController.
 */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'notes' => $this->notes,
            'properties_count' => $this->whenCounted('properties'),
            'projects_count' => $this->whenCounted('projects'),
            'properties' => PropertyResource::collection($this->whenLoaded('properties')),
            'projects' => ProjectSummaryResource::collection($this->whenLoaded('projects')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
