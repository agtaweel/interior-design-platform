<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /purchase-orders/{po}/receive. Body: `items: [{id, received_quantity,
 * actual_unit_price}]` — one entry per line being (partially or fully) delivered in this
 * receipt; a line omitted from the array is simply not updated (supports partial/staggered
 * deliveries across multiple receive() calls). Item ids are validated as integers only here;
 * PurchaseOrderController::receive() confirms each id actually belongs to this PO (rejecting
 * with 422 otherwise), since a `Rule::exists` here can't also verify the parent relationship
 * without an unwieldy closure duplicating that lookup.
 */
class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_PROCUREMENT);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.received_quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.actual_unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
