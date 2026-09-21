<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal project payload for embedding inside Client/Property resources — the full
 * ProjectResource (with the dashboard-style financial placeholders) is reserved for the
 * dedicated GET /projects and GET /projects/{id} endpoints.
 */
class ProjectSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status,
        ];
    }
}
