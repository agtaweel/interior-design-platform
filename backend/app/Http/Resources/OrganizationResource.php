<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PATCH /organizations/{organization} response shape (PROJECT_CONTEXT.md Sprint 8 "Settings").
 * No cost/margin concern — an organization's profile/branding fields carry nothing
 * financially sensitive.
 */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'logo_url' => $this->logo_url,
            'phone' => $this->phone,
            'email' => $this->email,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
