<?php

namespace App\Services\Projects;

use App\Models\BoqItem;
use App\Models\ChangeOrder;
use App\Models\Contract;
use App\Models\Handover;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Models\Snag;

/**
 * Where a project is in its life (quote → agreement → work → finish → done) and the single next
 * thing a person should do — the backing for the project screen's stage grouping and "Next step"
 * card (docs/SIMPLIFICATION_REVIEW.md P2.3).
 *
 * Read-only and derived entirely from existing records; nothing is stored. Returns only counts
 * and statuses — never amounts, costs or margins — so it is safe for every role that can open
 * the project.
 *
 * `next_step.tab` is the project tab the step happens on ("" = overview), matching the frontend's
 * route segments.
 */
class ProjectJourney
{
    /**
     * @return array{
     *     stage: 'quote'|'agreement'|'work'|'finish'|'done',
     *     next_step: array{key: string, tab: string},
     *     facts: array<string, int|string|bool|null>
     * }
     */
    public function describe(Project $project): array
    {
        $facts = $this->facts($project);

        [$stage, $key, $tab] = $this->decide($facts);

        return [
            'stage' => $stage,
            'next_step' => ['key' => $key, 'tab' => $tab],
            'facts' => $facts,
        ];
    }

    /** @return array<string, int|string|bool|null> */
    private function facts(Project $project): array
    {
        $contract = Contract::query()->where('project_id', $project->id)->latest('signed_at')->first();

        return [
            'boq_item_count' => BoqItem::query()->where('project_id', $project->id)->whereNull('archived_at')->count(),
            'latest_proposal_status' => ProposalVersion::query()
                ->where('project_id', $project->id)
                ->orderByDesc('version_no')
                ->value('status'),
            'has_contract' => $contract !== null,
            'payment_schedule_count' => $contract
                ? PaymentSchedule::query()->where('contract_id', $contract->id)->count()
                : 0,
            'approved_change_orders_to_apply' => ChangeOrder::query()
                ->where('project_id', $project->id)
                ->where('status', 'approved')
                ->count(),
            'snag_count' => Snag::query()->where('project_id', $project->id)->count(),
            'open_mandatory_snag_count' => Snag::query()
                ->where('project_id', $project->id)
                ->where('status', 'open')
                ->where('is_mandatory', true)
                ->count(),
            'has_handover' => Handover::query()->where('project_id', $project->id)->exists(),
            // P4.3 — lets the project screen label the sample project on every tab.
            'is_sample' => (bool) $project->is_sample,
        ];
    }

    /**
     * @param  array<string, int|string|bool|null>  $f
     * @return array{0: string, 1: string, 2: string} [stage, next-step key, tab]
     */
    private function decide(array $f): array
    {
        if ($f['has_handover']) {
            return ['done', 'handed_over', 'handover'];
        }

        if ($f['has_contract']) {
            // An approved extra that hasn't been applied yet means the contract value is stale —
            // that outranks everything else once the job is signed.
            if ($f['approved_change_orders_to_apply'] > 0) {
                return [$f['payment_schedule_count'] > 0 ? 'work' : 'agreement', 'apply_change_order', 'change-orders'];
            }

            if ($f['payment_schedule_count'] === 0) {
                return ['agreement', 'set_installments', 'payments'];
            }

            if ($f['snag_count'] > 0) {
                return $f['open_mandatory_snag_count'] > 0
                    ? ['finish', 'close_snags', 'snagging']
                    : ['finish', 'record_handover', 'handover'];
            }

            return ['work', 'track_work', 'execution'];
        }

        return match ($f['latest_proposal_status']) {
            'approved' => ['agreement', 'convert_to_contract', 'contract'],
            'sent' => ['quote', 'await_client', 'proposal'],
            'changes_requested' => ['quote', 'revise_proposal', 'proposal'],
            'draft' => ['quote', 'send_proposal', 'proposal'],
            default => $f['boq_item_count'] === 0
                ? ['quote', 'add_items', 'boq']
                : ['quote', 'create_proposal', 'proposal'],
        };
    }
}
