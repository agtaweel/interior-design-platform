<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /public/marketplace/organizations/{organization} — full profile shape (BRD v4 "Client
 * Marketplace"). Same exposure boundary as PublicOrganizationSummaryResource, plus the
 * portfolio media list (ids only — each file is fetched through
 * PublicOrganizationMediaController, never a direct disk URL).
 *
 * @mixin \App\Models\Organization
 */
class PublicOrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo_url' => $this->logo_url,
            'currency' => $this->currency,
            'description' => $this->profile?->description,
            'services_offered' => $this->profile?->services_offered ?? [],
            'service_area' => $this->profile?->service_area,
            'portfolio' => $this->getMedia('portfolio')->map(fn ($media) => [
                'id' => $media->id,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
            ])->values(),
        ];
    }
}
