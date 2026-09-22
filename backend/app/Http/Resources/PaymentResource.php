<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /payment-schedules/{id}/payments and the response of
 * POST /payment-schedules/{id}/payments (PROJECT_CONTEXT.md Sprint 6).
 *
 * Deliberately exposes `has_receipt` (a boolean), never the raw `receipt_url` storage path —
 * that path is an internal local-disk detail (see PaymentRecordingService::storeReceipt()) and
 * PROJECT_CONTEXT.md is explicit that access to the file itself must go through the
 * access-controlled GET /payments/{id}/receipt route, not a client-visible path string. This is
 * the same discipline as the rest of this codebase's resource-splitting convention (never leak
 * an internal representation just because it happens to be a string).
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'payment_schedule_id' => $this->payment_schedule_id,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at,
            'reference' => $this->reference,
            'has_receipt' => $this->receipt_url !== null,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
