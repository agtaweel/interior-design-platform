<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HandoverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'approved_by' => $this->whenLoaded('approvedBy', fn () => [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ]),
            'handover_date' => $this->handover_date,
            'warranty_period_months' => $this->warranty_period_months,
            'warranty_notes' => $this->warranty_notes,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
