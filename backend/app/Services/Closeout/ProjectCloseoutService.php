<?php

namespace App\Services\Closeout;

use App\Models\Project;
use App\Services\Finance\FinancialLedgerService;
use Illuminate\Support\Facades\DB;

/**
 * BRD v3 §12 "Reconciliation & Closeout": ACTIVE -> FINANCIAL_PENDING -> READY_FOR_CLOSE ->
 * CLOSED, plus the force-close escape hatch. This is the ONLY code path allowed to write
 * `projects.financial_status`/`financial_closed_at`/`financial_closed_by`/`force_closed`/
 * `force_close_reason` — all four are deliberately excluded from Project's #[Fillable] (see that
 * model's docblock), so a general PATCH /projects/{id} request can never touch them.
 *
 * Every transition here uses forceFill()->save() on a model that already has the Auditable
 * trait — the resulting `audit_logs` row (organization_id/entity_type=Project/entity_id/action=
 * updated/before_json/after_json) is what satisfies the BRD's "immutable audit" requirement for
 * a force-close, with no extra bookkeeping needed: Project is already audited on every field
 * change.
 */
final class ProjectCloseoutService
{
    public function __construct(private readonly FinancialLedgerService $ledger) {}

    /**
     * ACTIVE -> FINANCIAL_PENDING. Requires the project to be operationally 'completed' first
     * (per `projects.status`, the pre-existing lifecycle column) — reconciling the books is the
     * last step after the physical work and handover are done, not something that can start
     * mid-project.
     *
     * @throws CloseoutStateException
     */
    public function startReconciliation(Project $project): Project
    {
        if ($project->status !== 'completed') {
            throw new CloseoutStateException(
                'PROJECT_NOT_COMPLETED',
                'Financial reconciliation can only start once the project is operationally completed.',
            );
        }

        if ($project->financial_status !== Project::FINANCIAL_STATUS_ACTIVE) {
            throw new CloseoutStateException(
                'CLOSEOUT_NOT_ACTIVE',
                'Financial reconciliation has already started for this project.',
            );
        }

        $project->forceFill(['financial_status' => Project::FINANCIAL_STATUS_FINANCIAL_PENDING])->save();

        return $project->fresh();
    }

    /**
     * Evaluates whether the project's ledger is reconciled (outstanding == 0) and, if so,
     * transitions FINANCIAL_PENDING -> READY_FOR_CLOSE. Idempotent and side-effect-free when
     * NOT ready — this is a check-and-maybe-advance, safe to call repeatedly (e.g. a "check
     * readiness" button the UI can poll), never throws for "not ready yet", only for calling it
     * from a state where the check doesn't apply at all.
     *
     * @return array{ready: bool, outstanding: string|int, financial_status: string}
     *
     * @throws CloseoutStateException
     */
    public function checkReadiness(Project $project): array
    {
        if (! in_array($project->financial_status, [Project::FINANCIAL_STATUS_FINANCIAL_PENDING, Project::FINANCIAL_STATUS_READY_FOR_CLOSE], true)) {
            throw new CloseoutStateException(
                'CLOSEOUT_NOT_PENDING',
                'Reconciliation has not started for this project yet.',
            );
        }

        $outstanding = $this->ledger->summary($project)['outstanding'];
        $isReady = $outstanding === 0;

        if ($isReady && $project->financial_status === Project::FINANCIAL_STATUS_FINANCIAL_PENDING) {
            $project->forceFill(['financial_status' => Project::FINANCIAL_STATUS_READY_FOR_CLOSE])->save();
            $project = $project->fresh();
        }

        return [
            'ready' => $isReady,
            'outstanding' => $outstanding,
            'financial_status' => $project->financial_status,
        ];
    }

    /**
     * READY_FOR_CLOSE -> CLOSED. The normal (non-force) path — only reachable once
     * checkReadiness() has already confirmed outstanding=0.
     *
     * @throws CloseoutStateException
     */
    public function close(Project $project, int $userId): Project
    {
        if ($project->financial_status !== Project::FINANCIAL_STATUS_READY_FOR_CLOSE) {
            throw new CloseoutStateException(
                'CLOSEOUT_NOT_READY',
                'This project is not ready to close — outstanding balance must be zero first.',
            );
        }

        return DB::transaction(function () use ($project, $userId) {
            $project->forceFill([
                'financial_status' => Project::FINANCIAL_STATUS_CLOSED,
                'financial_closed_at' => now(),
                'financial_closed_by' => $userId,
            ])->save();

            return $project->fresh();
        });
    }

    /**
     * Force-close from ANY non-closed state directly to CLOSED, bypassing the normal
     * outstanding=0 gate entirely (BRD v3 §12's explicit escape hatch for e.g. writing off an
     * uncollectable balance). Requires a non-empty reason — enforced by
     * ForceCloseProjectRequest's validation before this method is ever called, not re-checked
     * here, matching this codebase's "controller/request validates input, service enforces
     * state" division of responsibility.
     *
     * @throws CloseoutStateException
     */
    public function forceClose(Project $project, int $userId, string $reason): Project
    {
        if ($project->financial_status === Project::FINANCIAL_STATUS_CLOSED) {
            throw new CloseoutStateException(
                'CLOSEOUT_ALREADY_CLOSED',
                'This project is already closed.',
            );
        }

        return DB::transaction(function () use ($project, $userId, $reason) {
            $project->forceFill([
                'financial_status' => Project::FINANCIAL_STATUS_CLOSED,
                'financial_closed_at' => now(),
                'financial_closed_by' => $userId,
                'force_closed' => true,
                'force_close_reason' => $reason,
            ])->save();

            return $project->fresh();
        });
    }
}
