<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET/POST/PATCH /leads(/{lead}) (BRD "CRM/Leads"). `converted_client`/`converted_project` are
 * only non-null once POST /leads/{lead}/convert has run — see LeadController::convert().
 */
class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'source' => $this->source,
            'status' => $this->status,
            'estimated_budget' => $this->estimated_budget,
            'notes' => $this->notes,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ] : null),
            'converted_client_id' => $this->converted_client_id,
            'converted_project_id' => $this->converted_project_id,
            'converted_at' => $this->converted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
