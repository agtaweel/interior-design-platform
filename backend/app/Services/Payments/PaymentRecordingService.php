<?php

namespace App\Services\Payments;

use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Services\Boq\BoqMoney;
use App\Services\Finance\FinancialLedgerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * POST /payment-schedules/{id}/payments (PROJECT_CONTEXT.md Sprint 6). The controller has
 * already: resolved the schedule (tenant-scoped), checked Permissions::MANAGE_BOQ, validated
 * the body via StorePaymentRequest, and handled the Idempotency-Key replay short-circuit
 * (mirroring PublicProposalController::approve() — see PaymentController's docblock). This
 * class only does the actual mutation: create the payment row, store the optional receipt
 * file, and recompute the schedule's cumulative-paid status — all inside one DB transaction so
 * a failure partway through (e.g. file storage failing) never leaves a payment row committed
 * without its receipt, or a schedule status stale relative to its payments.
 *
 * Reuses BoqMoney purely for its SCALE/zero() bcmath constants, same rationale as
 * PaymentScheduleService's docblock.
 */
final class PaymentRecordingService
{
    public function __construct(private readonly FinancialLedgerService $ledger) {}

    /**
     * @param  array{amount: string, payment_method: string, paid_at: string, reference?: ?string, notes?: ?string}  $data
     */
    public function record(PaymentSchedule $schedule, array $data, ?UploadedFile $receipt): Payment
    {
        return DB::transaction(function () use ($schedule, $data, $receipt) {
            // Payment is directly organization_id/project_id scoped (unlike PaymentSchedule's
            // three-hop indirection) — resolve both explicitly from the schedule's contract
            // chain rather than relying on BelongsToOrganization's auto-fill-from-TenantContext
            // behavior, since project_id has no such auto-fill and must be set regardless.
            $contract = $schedule->contract;
            $project = $contract->project;

            $payment = Payment::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'payment_schedule_id' => $schedule->id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'paid_at' => $data['paid_at'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($receipt) {
                $payment->forceFill([
                    'receipt_url' => $this->storeReceipt($receipt, $project->organization_id, $payment),
                ])->save();
            }

            $this->refreshScheduleStatus($schedule);

            $this->ledger->postFor($payment, [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'scope' => FinancialTransaction::SCOPE_CLIENT,
                'type' => FinancialTransaction::TYPE_CLIENT_PAYMENT,
                'amount' => (string) $payment->amount,
                'transaction_date' => $payment->paid_at->toDateString(),
                'created_by' => auth()->id(),
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Stores the uploaded receipt under Laravel's local disk (storage/app/private, per
     * config/filesystems.php) at "receipts/{organization_id}/{payment_id}.{ext}" — a
     * server-generated filename, never the client's original name, per PROJECT_CONTEXT.md's
     * explicit "generate the filename, don't trust the client's" instruction. Returns the
     * storage PATH (not a public URL); this is recorded verbatim into `payments.receipt_url`
     * and is only ever resolved back to bytes through the access-controlled
     * GET /payments/{id}/receipt route (see PaymentController::receipt()) — it is never
     * returned to a client directly.
     *
     * No virus scanning here (PROJECT_CONTEXT.md explicitly permits skipping it for this dev
     * environment, no ClamAV or equivalent available) — flagged here as a known gap for
     * production hardening rather than silently omitted, per that instruction.
     */
    private function storeReceipt(UploadedFile $file, int $organizationId, Payment $payment): string
    {
        $extension = $file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin');
        $filename = "{$payment->id}.{$extension}";

        return $file->storeAs("receipts/{$organizationId}", $filename, 'local');
    }

    /**
     * Support for partial payments (PROJECT_CONTEXT.md): sums ALL payments ever recorded
     * against this schedule (via bcmath, never a float-based SUM()) and flips status to 'paid'
     * once the cumulative total reaches or exceeds the schedule's `amount`. Uses `>=`
     * (bccomp(...) >= 0) rather than an exact match specifically so overpayment doesn't get
     * stuck as 'pending', and never flips a schedule back to 'pending' — this method only ever
     * moves status forward (pending -> paid), matching the "don't flip back to pending if
     * somehow overpaid" instruction by simply never writing 'pending' here at all.
     */
    private function refreshScheduleStatus(PaymentSchedule $schedule): void
    {
        if ($schedule->status === 'paid') {
            return;
        }

        $total = BoqMoney::sumAccessor(
            Payment::query()->where('payment_schedule_id', $schedule->id)->get(),
            'amount',
        );

        if (bccomp($total, (string) $schedule->amount, BoqMoney::SCALE) >= 0) {
            $schedule->forceFill(['status' => 'paid'])->save();
        }
    }
}
