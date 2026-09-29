<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\InvoiceDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * BRD v3 §7 "Invoice / Receipt Vault". The uploaded file itself lives in the shared Spatie
 * Media Library infrastructure (one 'file' collection) — same private-disk, access-controlled-
 * indirection posture as every other upload in this codebase. Not Auditable: this row IS
 * already an immutable record once posted (InvoiceController::store() never updates one after
 * creation — a correction uploads a NEW row with supersedes_id pointing back), so a parallel
 * audit-log trail would be redundant, same reasoning as SupplierPriceHistory.
 */
#[Fillable([
    'organization_id', 'project_id', 'supplier_id', 'invoice_number', 'invoice_date',
    'amount', 'vat_amount', 'currency', 'room_id', 'boq_item_id', 'payment_status',
    'file_fingerprint', 'supersedes_id', 'uploaded_by',
])]
class InvoiceDocument extends Model implements HasMedia
{
    /** @use HasFactory<InvoiceDocumentFactory> */
    use HasFactory, BelongsToOrganization, InteractsWithMedia;

    public const FILE_COLLECTION = 'file';

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::FILE_COLLECTION)->singleFile();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * Inverse of supersedes() — rows that point BACK at this one as their correction target.
     * Used by InvoiceDocumentResource to compute `is_superseded` and by scopeCurrent() below to
     * filter a listing down to only the latest version of each invoice.
     */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Excludes any row that another row's supersedes_id points at — i.e. only the current
     * (latest) version of each invoice, hiding corrected/stale ones from the default listing.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotIn('id', function ($subquery) {
            $subquery->select('supersedes_id')
                ->from('invoice_documents')
                ->whereNotNull('supersedes_id');
        });
    }
}
