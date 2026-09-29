<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SnagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'description' => $this->description,
            'priority' => $this->priority,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ] : null),
            'due_date' => $this->due_date,
            'status' => $this->status,
            'is_mandatory' => $this->is_mandatory,
            'resolution_notes' => $this->resolution_notes,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
        ];
    }
}
