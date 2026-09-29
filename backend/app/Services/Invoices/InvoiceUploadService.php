<?php

namespace App\Services\Invoices;

use App\Models\InvoiceDocument;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BRD v3 §7 "Invoice / Receipt Vault": upload + correction (supersede) for supplier invoices/
 * receipts. Two invariants this class exists to enforce:
 *
 *  - Duplicate detection BEFORE storage: a new upload matching an existing document's file
 *    content (file_fingerprint, a sha256 of the raw bytes — catches the same PDF/photo
 *    re-uploaded verbatim) OR its vendor/invoice_number/amount/invoice_date combination (catches
 *    the same paper invoice re-keyed by hand with a different scan) is rejected with
 *    DuplicateInvoiceException rather than silently creating a second row that could later be
 *    paid twice. Checked against EVERY existing row for the project regardless of
 *    superseded/current status — a duplicate submitted long after the original was corrected is
 *    still a duplicate.
 *
 *  - Immutability via supersede(), never edit: correcting a mis-keyed invoice creates a NEW row
 *    with supersedes_id pointing at the original; the original's amount/date/fingerprint/file
 *    are never touched. Both upload() and supersede() run the same duplicate check — a
 *    "correction" that actually just re-matches another existing row is still a duplicate, not
 *    a valid correction.
 */
final class InvoiceUploadService
{
    /**
     * @param  array{supplier_id?: ?int, invoice_number?: ?string, invoice_date: string, amount: string, vat_amount?: ?string, currency?: ?string, room_id?: ?int, boq_item_id?: ?int, payment_status?: ?string}  $data
     *
     * @throws DuplicateInvoiceException
     */
    public function upload(Project $project, array $data, UploadedFile $file, int $uploadedBy): InvoiceDocument
    {
        return $this->store($project, $data, $file, $uploadedBy, supersedesId: null);
    }

    /**
     * @param  array{supplier_id?: ?int, invoice_number?: ?string, invoice_date: string, amount: string, vat_amount?: ?string, currency?: ?string, room_id?: ?int, boq_item_id?: ?int, payment_status?: ?string}  $data
     *
     * @throws DuplicateInvoiceException
     */
    public function supersede(InvoiceDocument $original, array $data, UploadedFile $file, int $uploadedBy): InvoiceDocument
    {
        return $this->store($original->project, $data, $file, $uploadedBy, supersedesId: $original->id);
    }

    /**
     * @param  array{supplier_id?: ?int, invoice_number?: ?string, invoice_date: string, amount: string, vat_amount?: ?string, currency?: ?string, room_id?: ?int, boq_item_id?: ?int, payment_status?: ?string}  $data
     */
    private function store(Project $project, array $data, UploadedFile $file, int $uploadedBy, ?int $supersedesId): InvoiceDocument
    {
        $fingerprint = hash_file('sha256', $file->getRealPath());

        $duplicate = $this->findDuplicate($project, $data, $fingerprint);

        if ($duplicate) {
            throw new DuplicateInvoiceException($duplicate->id);
        }

        return DB::transaction(function () use ($project, $data, $file, $uploadedBy, $supersedesId, $fingerprint) {
            $invoice = InvoiceDocument::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'invoice_number' => $data['invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'amount' => $data['amount'],
                'vat_amount' => $data['vat_amount'] ?? null,
                'currency' => $data['currency'] ?? 'EGP',
                'room_id' => $data['room_id'] ?? null,
                'boq_item_id' => $data['boq_item_id'] ?? null,
                'payment_status' => $data['payment_status'] ?? 'unpaid',
                'file_fingerprint' => $fingerprint,
                'supersedes_id' => $supersedesId,
                'uploaded_by' => $uploadedBy,
            ]);

            $invoice->addMedia($file)->toMediaCollection(InvoiceDocument::FILE_COLLECTION);

            return $invoice->fresh();
        });
    }

    /**
     * Checked as two separate queries rather than one combined OR, specifically so the
     * vendor/invoice_number/amount/invoice_date branch can compare `invoice_date` through the
     * model's own Carbon-cast attribute in PHP rather than as a raw SQL string equality —
     * Eloquent's date-cast serialization format for a `date`-cast column is not guaranteed to
     * be a bare 'Y-m-d' on every driver (it can carry a time-of-day suffix depending on the
     * connection's date-storage format), so comparing the raw request string directly against
     * the stored column via SQL `=` can silently never match. Filtering by the cheap, exact-text
     * fields (supplier_id/invoice_number/amount) in SQL first keeps the candidate set small
     * before doing the date comparison safely in PHP.
     *
     * @param  array{supplier_id?: ?int, invoice_number?: ?string, invoice_date: string, amount: string}  $data
     */
    private function findDuplicate(Project $project, array $data, string $fingerprint): ?InvoiceDocument
    {
        $byFingerprint = InvoiceDocument::query()
            ->where('project_id', $project->id)
            ->where('file_fingerprint', $fingerprint)
            ->first();

        if ($byFingerprint) {
            return $byFingerprint;
        }

        if (empty($data['supplier_id']) || empty($data['invoice_number'])) {
            return null;
        }

        $targetDate = Carbon::parse($data['invoice_date'])->toDateString();

        return InvoiceDocument::query()
            ->where('project_id', $project->id)
            ->where('supplier_id', $data['supplier_id'])
            ->where('invoice_number', $data['invoice_number'])
            ->where('amount', $data['amount'])
            ->get()
            ->first(fn (InvoiceDocument $candidate) => $candidate->invoice_date->toDateString() === $targetDate);
    }
}
