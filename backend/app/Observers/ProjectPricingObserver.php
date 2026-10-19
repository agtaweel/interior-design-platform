<?php

namespace App\Observers;

use App\Services\Pricing\ProjectPricingCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Attached (via #[ObservedBy]) to BoqItem and PricingRule — the two inputs PricingCalculator
 * reads. Any create/update/archive/delete queues a refresh of the owning project's pricing cache;
 * see ProjectPricingCache for the de-duplication and transaction handling.
 *
 * Only fires for Eloquent model writes. Nothing in the codebase writes these tables through the
 * query builder today; anything added that does must call ProjectPricingCache itself.
 */
class ProjectPricingObserver
{
    public function __construct(private readonly ProjectPricingCache $cache) {}

    public function saved(Model $model): void
    {
        $this->cache->refreshAfterChange($model->getAttribute('project_id'));
    }

    public function deleted(Model $model): void
    {
        $this->cache->refreshAfterChange($model->getAttribute('project_id'));
    }
}
