<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /public/marketplace/organizations — browse-grid card shape (BRD v4 "Client Marketplace").
 * Deliberately exposes only what a browsing, unauthenticated prospective client should see —
 * never `settings_json`, `legal_name`, `phone`/`email`, or any billing-style field from
 * `Organization` itself.
 *
 * @mixin \App\Models\Organization
 */
class PublicOrganizationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo_url' => $this->logo_url,
            'description' => $this->profile?->description,
            'services_offered' => $this->profile?->services_offered ?? [],
            'service_area' => $this->profile?->service_area,
            'cover_media_id' => $this->getFirstMedia('portfolio')?->id,
        ];
    }
}
