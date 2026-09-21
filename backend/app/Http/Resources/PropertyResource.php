<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => new ClientSummaryResource($this->whenLoaded('client')),
            'type' => $this->type,
            'compound' => $this->compound,
            'address' => $this->address,
            'area_m2' => $this->area_m2,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'metadata_json' => $this->metadata_json,
            'projects_count' => $this->whenCounted('projects'),
            'projects' => ProjectSummaryResource::collection($this->whenLoaded('projects')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
