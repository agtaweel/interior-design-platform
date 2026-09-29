<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /invoices/{invoice}/supersede. Same fields as StoreInvoiceDocumentRequest, but this
 * route has no {project} route parameter (the project is resolved from the ORIGINAL invoice
 * being superseded, in the controller) — room_id/boq_item_id are scoped by organization_id
 * instead of project_id here, one step coarser than the create-time check, which is an
 * acceptable trade-off for a correction endpoint InvoiceUploadService::supersede() itself
 * re-derives the project from anyway.
 */
class SupersedeInvoiceDocumentRequest extends FormRequest
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
                Rule::exists('rooms', 'id')->where(fn ($query) => $query->whereIn('project_id', function ($sub) use ($organizationId) {
                    $sub->select('id')->from('projects')->where('organization_id', $organizationId);
                })),
            ],
            'boq_item_id' => [
                'nullable',
                'integer',
                Rule::exists('boq_items', 'id')->where(fn ($query) => $query->whereIn('project_id', function ($sub) use ($organizationId) {
                    $sub->select('id')->from('projects')->where('organization_id', $organizationId);
                })),
            ],
            'payment_status' => ['nullable', 'string', Rule::in(['unpaid', 'partially_paid', 'paid'])],
        ];
    }
}
