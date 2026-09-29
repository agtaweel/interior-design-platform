<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BRD v3 §7 "Invoice / Receipt Vault". Deliberately exposes no raw disk path or
 * file_fingerprint (an internal dedup key, not user-facing data) — the file itself is only
 * reachable via GET /invoices/{invoice}/file, same access-controlled-indirection discipline as
 * PaymentResource/ProjectMediaResource.
 *
 * `is_superseded` is computed from the `supersedes` inverse relation being loaded (see
 * InvoiceDocumentController::index()'s eager-load of `supersededBy`) — true if some other row
 * points back at this one via supersedes_id, i.e. this is a stale/corrected version, not the
 * current one.
 *
 * @mixin \App\Models\InvoiceDocument
 */
class InvoiceDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'supplier_id' => $this->supplier_id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'amount' => $this->amount,
            'vat_amount' => $this->vat_amount,
            'currency' => $this->currency,
            'room_id' => $this->room_id,
            'boq_item_id' => $this->boq_item_id,
            'payment_status' => $this->payment_status,
            'supersedes_id' => $this->supersedes_id,
            'is_superseded' => $this->relationLoaded('supersededBy') ? $this->supersededBy->isNotEmpty() : null,
            'uploaded_by' => $this->uploadedBy?->name,
            'created_at' => $this->created_at,
        ];
    }
}
