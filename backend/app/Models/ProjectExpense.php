<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ProjectExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carries organization_id directly (like Payment, unlike Contract/PurchaseOrder) — see this
 * table's migration docblock for why.
 */
#[Fillable([
    'organization_id', 'project_id', 'supplier_id', 'category', 'description', 'amount',
    'expense_date', 'notes',
])]
class ProjectExpense extends Model
{
    /** @use HasFactory<ProjectExpenseFactory> */
    use HasFactory, BelongsToOrganization, Auditable;

    /** receipt_url deliberately excluded from #[Fillable] above — only ever written by
     *  ExpenseRecordingService via forceFill(), matching Payment's identical convention. */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
