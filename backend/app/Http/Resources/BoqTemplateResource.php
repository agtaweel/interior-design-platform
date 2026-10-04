<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoqTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'is_system' => $this->organization_id === null,
            'code' => $this->code,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'description' => $this->description,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'template_type' => $this->template_type,
            'project_type' => $this->project_type,
            'finishing_level' => $this->finishing_level,
            'is_active' => $this->is_active,
            'active_version' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion ? [
                'id' => $this->activeVersion->id,
                'version_number' => $this->activeVersion->version_number,
                'status' => $this->activeVersion->status,
                'published_at' => $this->activeVersion->published_at,
            ] : null),
            'versions_count' => $this->whenCounted('versions'),
            'applications_count' => $this->whenCounted('applications'),
        ];
    }
}
