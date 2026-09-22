<?php

namespace App\Services\Proposals;

use App\Models\Project;
use App\Models\ProposalItem;
use App\Models\ProposalVersion;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Draft-lifecycle operations for proposal versions (PROJECT_CONTEXT.md Sprint 4 "API" —
 * create-draft and the re-snapshot half of PATCH). Sending (draft -> sent, snapshot
 * finalization, signed link + OTP issuance) is a separate concern — see ProposalSendService —
 * kept apart because sending touches SignedLinkService/OtpChallengeService too and this class
 * should stay focused on "what a draft version's items/totals are right now".
 *
 * Every public method here wraps its work in DB::transaction() per PROJECT_CONTEXT.md's NFR
 * that commercial mutations (creating a proposal's frozen line items + totals) are
 * transactional.
 */
class ProposalVersionService
{
    public function __construct(private readonly PricingCalculator $calculator) {}

    /**
     * POST /projects/{id}/proposals. version_no = max existing for this project + 1 (starts at
     * 1) — computed inside the transaction so two concurrent create calls for the same project
     * can't both compute the same next number (the unique(project_id, version_no) DB
     * constraint would reject the loser anyway, but doing the max()+1 read-then-write inside
     * the transaction keeps the common case race-free under Postgres's default read-committed
     * isolation without needing an explicit row lock — a rare double-submit is an acceptable
     * failure mode here: the second request gets a DB unique-violation, not a silently wrong
     * version_no).
     */
    public function createDraft(Project $project, ?array $contentJson, ?int $createdBy): ProposalVersion
    {
        return DB::transaction(function () use ($project, $contentJson, $createdBy) {
            $nextVersionNo = (int) ProposalVersion::query()
                ->where('project_id', $project->id)
                ->max('version_no') + 1;

            $version = ProposalVersion::create([
                'project_id' => $project->id,
                'version_no' => $nextVersionNo,
                'status' => 'draft',
                'content_json' => $contentJson,
                'created_by' => $createdBy,
            ]);

            $this->snapshotItemsFromBoq($version, $project);
            $this->applyPricingTotals($version, $project);

            return $version->fresh(['items']);
        });
    }

    /**
     * PATCH /proposals/{id} with resnapshot=true. Re-pulls CURRENT non-archived boq_items and
     * CURRENT pricing into this draft version — per PROJECT_CONTEXT.md's immutability rule,
     * this is only ever called while status == 'draft' (the controller enforces that before
     * calling in; this method does not re-check, since ProposalSendService::send() has already
     * transitioned status by the time anything downstream could call it, and nothing else in
     * this codebase calls resnapshot() on a non-draft version).
     */
    public function resnapshot(ProposalVersion $version): ProposalVersion
    {
        return DB::transaction(function () use ($version) {
            $project = $version->project;

            $this->snapshotItemsFromBoq($version, $project);
            $this->applyPricingTotals($version, $project);

            return $version->fresh(['items']);
        });
    }

    /**
     * Replaces this version's proposal_items with a fresh frozen copy of the project's
     * CURRENT non-archived boq_items. delete()-then-recreate rather than diffing: proposal
     * items have no independent identity a client would need preserved across a resnapshot
     * (they're deleted wholesale on version delete anyway per the migration's cascadeOnDelete),
     * and a full replace is simplest/safest against drift.
     */
    private function snapshotItemsFromBoq(ProposalVersion $version, Project $project): void
    {
        $version->items()->delete();

        $boqItems = $project->boqItems()
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($boqItems as $boqItem) {
            ProposalItem::create([
                'proposal_version_id' => $version->id,
                'source_boq_item_id' => $boqItem->id,
                // Falls back to the BOQ item's name if it has no separate description — the
                // client-facing line needs SOME text, and boq_items.description is nullable
                // while name is required.
                'description' => $boqItem->description ?: $boqItem->name,
                'quantity' => $boqItem->quantity,
                'unit' => $boqItem->unit,
                'unit_price' => $boqItem->client_unit_price,
                'line_total' => $boqItem->client_total,
            ]);
        }
    }

    /**
     * Calls Sprint 3's PricingCalculator directly (per PROJECT_CONTEXT.md's explicit
     * instruction not to re-derive the math) against the project's CURRENT boq_items/
     * pricing_rules, and copies the result onto this version's own totals columns.
     * `client_subtotal` maps to `proposal_versions.subtotal` — the pre-markup/fee/discount
     * anchor, same value `pricing_rules` with base_selector=boq_client_subtotal would use.
     * `direct_cost_total` is deliberately NOT copied anywhere on this model — proposal_items/
     * proposal_versions are inherently client-facing-shaped tables (see ProposalItem's
     * docblock) and must never carry a cost figure.
     *
     * forceFill(), not fill()/update(): these five columns are deliberately not in
     * ProposalVersion's mass-assignment... actually they ARE in #[Fillable] (unlike Project's
     * cache columns) since a version's totals are legitimately part of its own creation
     * payload conceptually — but this method still uses forceFill()+save() rather than
     * ->update() to avoid re-triggering FormRequest-shaped validation assumptions and to read
     * clearly as "the pricing engine, not the client request, decided these values."
     */
    private function applyPricingTotals(ProposalVersion $version, Project $project): array
    {
        $result = $this->calculator->calculate($project);

        $version->forceFill([
            'subtotal' => $result['client_subtotal'],
            'markup_total' => $result['markup_total'],
            'fees_total' => $result['fees_total'],
            'discount_total' => $result['discount_total'],
            'grand_total' => $result['grand_total'],
        ])->save();

        return $result;
    }
}
