<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'service_type' => $this->service_type,
            'pricing_method' => $this->pricing_method,
            'price' => $this->price,
            'metadata_json' => $this->metadata_json,
            'created_at' => $this->created_at,
        ];
    }
}
