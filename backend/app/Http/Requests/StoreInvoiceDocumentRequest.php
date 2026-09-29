<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/invoices (BRD v3 §7 "Invoice / Receipt Vault"). Multipart body — the
 * uploaded file is the invoice/receipt itself, everything else is the office's own transcription
 * of what's on it (amount/date/vendor), which is what InvoiceUploadService's duplicate-detection
 * check runs against. Mutation gated behind Permissions::MANAGE_PROCUREMENT, same "gates both
 * reads and writes, profit-adjacent data" posture that permission already has for suppliers/POs.
 */
class StoreInvoiceDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_PROCUREMENT);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'room_id' => [
                'nullable',
                'integer',
                Rule::exists('rooms', 'id')->where(fn ($query) => $query->where('project_id', $this->route('project'))),
            ],
            'boq_item_id' => [
                'nullable',
                'integer',
                Rule::exists('boq_items', 'id')->where(fn ($query) => $query->where('project_id', $this->route('project'))),
            ],
            'payment_status' => ['nullable', 'string', Rule::in(['unpaid', 'partially_paid', 'paid'])],
        ];
    }
}
