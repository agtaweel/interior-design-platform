<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unlike PaymentSchedule (indirect, three-hop), this table carries organization_id AND
 * project_id directly, per the ERD's own choice (PROJECT_CONTEXT.md Sprint 6) — payments must
 * be queryable project-wide independent of which contract/schedule they're against. Uses
 * BelongsToOrganization directly, same as Client/Project, rather than an
 * auditOrganizationId() override: Auditable's default resolution (read the model's own
 * organization_id attribute) is sufficient here, no override needed.
 */
#[Fillable([
    'organization_id', 'project_id', 'payment_schedule_id', 'amount', 'payment_method',
    'paid_at', 'reference', 'receipt_url', 'notes',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, BelongsToOrganization, Auditable;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class);
    }
}
