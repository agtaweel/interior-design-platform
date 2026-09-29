<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'reported_by' => $this->whenLoaded('reportedBy', fn () => [
                'id' => $this->reportedBy->id,
                'name' => $this->reportedBy->name,
            ]),
            'report_date' => $this->report_date,
            'work_done' => $this->work_done,
            'issues' => $this->issues,
            'decisions' => $this->decisions,
            'photos' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id' => $m->id,
                'file_name' => $m->file_name,
                'mime_type' => $m->mime_type,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
