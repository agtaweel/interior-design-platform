<?php

namespace App\Services\Expenses;

use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Services\Finance\FinancialLedgerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * POST /projects/{project}/expenses. Mirrors PaymentRecordingService's structure/rationale
 * exactly — same receipt-storage discipline (server-generated filename under the private
 * local disk, path never returned to the client directly), just without a schedule-status
 * side effect to recompute.
 */
final class ExpenseRecordingService
{
    public function __construct(private readonly FinancialLedgerService $ledger) {}

    /**
     * @param  array{supplier_id?: ?int, category: string, description: string, amount: string, expense_date: string, notes?: ?string}  $data
     */
    public function record(Project $project, array $data, ?UploadedFile $receipt): ProjectExpense
    {
        return DB::transaction(function () use ($project, $data, $receipt) {
            $expense = ProjectExpense::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'category' => $data['category'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            if ($receipt) {
                $expense->forceFill([
                    'receipt_url' => $this->storeReceipt($receipt, $project->organization_id, $expense),
                ])->save();
            }

            $this->ledger->postFor($expense, [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'scope' => FinancialTransaction::SCOPE_COST,
                'type' => FinancialTransaction::TYPE_EXPENSE,
                'amount' => (string) $expense->amount,
                'transaction_date' => (string) $expense->expense_date->toDateString(),
                'created_by' => auth()->id(),
            ]);

            return $expense->fresh();
        });
    }

    /**
     * Same "receipts/{organization_id}/{id}.{ext}" server-generated-filename convention as
     * PaymentRecordingService::storeReceipt() — see that method's docblock for the full
     * rationale (never trust the client's filename, no virus scanning in this dev
     * environment).
     */
    private function storeReceipt(UploadedFile $file, int $organizationId, ProjectExpense $expense): string
    {
        $extension = $file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin');
        $filename = "{$expense->id}.{$extension}";

        return $file->storeAs("expense-receipts/{$organizationId}", $filename, 'local');
    }
}
