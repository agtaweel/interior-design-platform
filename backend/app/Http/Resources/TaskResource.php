<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `photos` is only populated when the controller eager-loaded the `media` relation (see
 * TaskController) — each entry exposes only what's needed to render a thumbnail/download link
 * via GET /execution-media/{id}/file (ExecutionMediaController), never a raw disk path, same
 * discipline as ProjectMediaResource.
 */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'status' => $this->status,
            'due_date' => $this->due_date,
            'sort_order' => $this->sort_order,
            'photos' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id' => $m->id,
                'file_name' => $m->file_name,
                'mime_type' => $m->mime_type,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
