<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\FinancialTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * BRD v3 §4/§6 "Strict Financial Ledger — Core Differentiator" — see the migration's docblock
 * for the full write-side design (why `amount` carries its own natural sign for
 * change_order_charge/adjustment but is a positive magnitude for everything else, and why a
 * reversal is a status flip + audit-trail mirror row rather than an in-place edit). Read side
 * lives in FinancialLedgerService, never here — this model is intentionally dumb (a typed row +
 * small helpers), all aggregation logic is centralized in one place per BRD §27 "one source of
 * truth."
 */
#[Fillable([
    'organization_id', 'project_id', 'scope', 'type', 'amount', 'currency', 'transaction_date',
    'status', 'source_entity_type', 'source_entity_id', 'source_document_id', 'created_by',
    'posted_by', 'posted_at', 'reversal_of_id', 'notes', 'metadata',
])]
class FinancialTransaction extends Model
{
    /** @use HasFactory<FinancialTransactionFactory> */
    use HasFactory, BelongsToOrganization;

    public const SCOPE_CLIENT = 'client';
    public const SCOPE_COST = 'cost';

    public const TYPE_CONTRACT_CHARGE = 'contract_charge';
    public const TYPE_CHANGE_ORDER_CHARGE = 'change_order_charge';
    public const TYPE_CLIENT_PAYMENT = 'client_payment';
    public const TYPE_REFUND = 'refund';
    public const TYPE_CREDIT = 'credit';
    public const TYPE_SUPPLIER_INVOICE = 'supplier_invoice';
    public const TYPE_EXPENSE = 'expense';
    public const TYPE_CONTRACTOR_COST = 'contractor_cost';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REVERSAL = 'reversal';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
            'metadata' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sourceEntity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_entity_type', 'source_entity_id');
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(InvoiceDocument::class, 'source_document_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
