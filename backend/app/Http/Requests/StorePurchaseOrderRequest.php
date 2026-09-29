<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /projects/{project}/purchase-orders. Always created as 'draft' — status only ever
 * changes via the dedicated send()/receive()/cancel() actions (PurchaseOrderController), never
 * a direct PATCH, mirroring ProposalVersion/ChangeOrder's own send-is-a-separate-action
 * convention.
 */
class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_PROCUREMENT);
    }

    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->organizationId();

        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'po_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.quoted_unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
