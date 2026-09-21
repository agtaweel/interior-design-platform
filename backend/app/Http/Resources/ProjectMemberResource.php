<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'role' => $this->role,
            'created_at' => $this->created_at,
        ];
    }
}
