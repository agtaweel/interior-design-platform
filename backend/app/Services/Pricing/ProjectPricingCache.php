<?php

namespace App\Services\Pricing;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Owns the `projects` pricing cache columns (direct_cost_total … grand_total, priced_at) — the
 * persisted totals behind ProjectResource.financials.value, the dashboard and reports.
 *
 * Two entry points:
 *  - refresh(): run PricingCalculator now and persist. Used by POST /pricing/recalculate.
 *  - refreshAfterChange(): called by ProjectPricingObserver whenever a BOQ item or pricing rule
 *    changes, so the cache no longer depends on someone remembering to press "Recalculate".
 *    Inside a DB transaction (CSV import, template apply, change-order apply) the refresh is
 *    deferred to commit and de-duplicated per project, so a 200-row import recalculates once,
 *    not 200 times; on rollback the pending marker is dropped and nothing is written.
 *
 * The automatic path only writes when a total actually changed (or the project was never
 * priced), so cosmetic edits — renaming an item, editing notes — don't bump priced_at or add a
 * project audit row. The explicit endpoint keeps its original always-write semantics.
 *
 * Bound as a scoped singleton (AppServiceProvider) so the pending set lives for one request/job.
 */
class ProjectPricingCache
{
    /** @var array<string, true> project ids with a refresh already queued for commit */
    private array $pending = [];

    public function __construct(private readonly PricingCalculator $calculator) {}

    /**
     * @return array<string, mixed> the PricingCalculator result
     */
    public function refresh(Project $project, bool $onlyIfChanged = false): array
    {
        $result = $this->calculator->calculate($project);

        // forceFill(): the cache columns are deliberately outside Project's #[Fillable] so no
        // client-supplied PATCH can write them — this service is their one legitimate writer.
        $project->forceFill([
            'direct_cost_total' => $result['direct_cost_total'],
            'client_subtotal' => $result['client_subtotal'],
            'markup_total' => $result['markup_total'],
            'fees_total' => $result['fees_total'],
            'discount_total' => $result['discount_total'],
            'grand_total' => $result['grand_total'],
        ]);

        if (! $onlyIfChanged || $project->isDirty() || $project->priced_at === null) {
            $project->forceFill(['priced_at' => now()])->save();
        }

        return $result;
    }

    public function refreshAfterChange(int|string|null $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        $key = (string) $projectId;

        if (isset($this->pending[$key])) {
            return;
        }

        $this->pending[$key] = true;

        // Runs immediately when no transaction is open; otherwise once the outermost one commits.
        DB::afterRollBack(function () use ($key) {
            unset($this->pending[$key]);
        });
        DB::afterCommit(function () use ($key) {
            unset($this->pending[$key]);

            $project = Project::find($key);

            if ($project) {
                $this->refresh($project, onlyIfChanged: true);
            }
        });
    }
}
