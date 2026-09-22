<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /contracts/{id}/payment-schedules and the response of
 * POST /contracts/{id}/payment-schedules (PROJECT_CONTEXT.md Sprint 6).
 *
 * `is_overdue`/`is_upcoming` are computed via PaymentSchedule::isOverdue()/isUpcoming() (never
 * reimplemented here) — per the model's docblock these are deliberately NOT stored columns, so
 * this is the read-time surface the S13 UX's overdue/upcoming/paid filter tabs are meant to
 * consume. `is_paid` is a thin convenience mirror of `status === 'paid'` for symmetry with the
 * other two computed booleans, so the frontend can filter on three flat booleans without also
 * needing to know the raw `status` string's exact value.
 */
class PaymentScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'name' => $this->name,
            'sequence_no' => $this->sequence_no,
            'due_date' => $this->due_date,
            'percentage' => $this->percentage,
            'amount' => $this->amount,
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(),
            'is_upcoming' => $this->isUpcoming(),
            'is_paid' => $this->status === 'paid',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
