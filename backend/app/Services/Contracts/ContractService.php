<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Services\Finance\FinancialLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * POST /projects/{id}/contracts/from-proposal/{proposalId} (PROJECT_CONTEXT.md Sprint 5). The
 * controller has already validated: the proposal version belongs to this project, its status is
 * 'approved', and no contract exists for it yet — this class only does the actual creation.
 *
 * Per the Sprint 5 "Key design decision", creation IS signing (no separate e-signature/OTP step
 * for the contract itself, since the client already OTP-approved the proposal in Sprint 4) —
 * `signed_at` is set unconditionally to now() here.
 */
class ContractService
{
    /**
     * Bounds the contract_no conflict-retry loop (see generateContractNo()'s docblock) — a
     * finite cap so a persistently broken sequence fails loudly (QueryException bubbles up to
     * the global 500 handler) rather than looping forever.
     */
    private const MAX_CONTRACT_NO_ATTEMPTS = 5;

    public function __construct(private readonly FinancialLedgerService $ledger) {}

    /**
     * contract_value is an EXACT copy of proposal_version.grand_total — never recalculated,
     * per the immutability boundary PROJECT_CONTEXT.md draws around this sprint's contracts
     * (distinct from proposal_versions', which freezes at `sent` not `approved`; here the
     * whole row is born frozen). terms_json is seeded once from content_json and becomes the
     * contract's own independent copy from this point on — see seedTermsJson().
     */
    public function fromProposal(Project $project, ProposalVersion $proposalVersion): Contract
    {
        $termsJson = $this->seedTermsJson($proposalVersion->content_json ?? []);

        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                // Inner DB::transaction(), not just one at the top: Laravel issues a real
                // SAVEPOINT for a transaction started while already inside one (Postgres/MySQL/
                // SQL Server all support this), so a caught QueryException here only rolls back
                // to the savepoint instead of aborting the whole surrounding transaction the way
                // a raw failed INSERT would under Postgres's "transaction aborted, commands
                // ignored until end of transaction block" behavior. That's what makes the
                // contract_no retry loop below safe to run inside a transaction at all.
                return DB::transaction(function () use ($project, $proposalVersion, $termsJson) {
                    $contract = Contract::create([
                        'project_id' => $project->id,
                        'proposal_version_id' => $proposalVersion->id,
                        'contract_no' => $this->generateContractNo(),
                        'status' => 'active',
                        'contract_value' => $proposalVersion->grand_total,
                        'signed_at' => now(),
                        'terms_json' => $termsJson,
                    ]);

                    // BRD v3 §4 "every contract posts a contract_charge" — the ledger's
                    // obligation figure derives solely from posted transactions, never from
                    // contract_value directly, so this is not optional bookkeeping.
                    $this->ledger->postFor($contract, [
                        'organization_id' => $project->organization_id,
                        'project_id' => $project->id,
                        'scope' => FinancialTransaction::SCOPE_CLIENT,
                        'type' => FinancialTransaction::TYPE_CONTRACT_CHARGE,
                        'amount' => (string) $contract->contract_value,
                        'transaction_date' => now()->toDateString(),
                        'created_by' => auth()->id(),
                    ]);

                    return $contract;
                });
            } catch (QueryException $e) {
                if ($this->isContractNoConflict($e) && $attempts < self::MAX_CONTRACT_NO_ATTEMPTS) {
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Best-effort sequential contract code (e.g. "CTR-00007"), mirroring
     * ProjectController::generateProjectCode()'s "PRJ-00001" pattern exactly, with one
     * difference: contracts have no organization_id to scope the count() by (see the migration's
     * docblock — contract_no is globally unique, not per-organization), so this counts across
     * all tenants. A collision under concurrent creation is caught by the retry loop in
     * fromProposal() above rather than surfaced to the caller as a 409 — unlike projects.code,
     * contract_no is never client-supplied, so there's no "your input conflicted" case to report;
     * retrying with the next number is the correct recovery, not an error.
     */
    private function generateContractNo(): string
    {
        $next = Contract::query()->count() + 1;

        return 'CTR-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Narrower than ProjectController::isUniqueViolation() — checks the violation actually
     * mentions contract_no specifically (both Postgres's constraint-name and sqlite's
     * "UNIQUE constraint failed: contracts.contract_no" message formats include the column name)
     * before treating it as retryable. A unique violation on `proposal_version_id` instead (a
     * genuine duplicate-conversion race, distinct from a contract_no collision) must NOT be
     * silently retried — it should bubble up so the caller sees a real error rather than an
     * infinite/misleading contract_no retry loop.
     */
    private function isContractNoConflict(QueryException $e): bool
    {
        $isUniqueViolation = $e->getCode() === '23505' || str_contains(strtolower($e->getMessage()), 'unique constraint');

        return $isUniqueViolation && str_contains(strtolower($e->getMessage()), 'contract_no');
    }

    /**
     * Seeds the contract's terms_json from the source proposal's content_json at creation time,
     * as the contract's OWN independent copy from that point on (PROJECT_CONTEXT.md: "store it
     * as the contract's own independent copy so ... future contract edits don't entangle the two
     * records"). Chosen shape: copy the four content_json keys the proposal editor/PDF already
     * treat as terms-adjacent (terms, exclusions, timeline, payment_plan — see
     * ProposalPresenter/resources/views/proposals/pdf.blade.php's $sections list) verbatim under
     * the same key names, plus two empty contract-specific slots (`warranty_period`,
     * `cancellation_policy`) that have no proposal-side equivalent but are exactly the kind of
     * contract-only addition S12's UX spec implies ("terms ... mirroring the proposal editor's
     * content-section pattern") — present as explicit null slots rather than omitted keys so the
     * PATCH-editable shape is discoverable/self-documenting from a single GET.
     *
     * Deliberately does NOT copy `cover_note`/`scope` — those are proposal-presentation content
     * (what the client was shown pre-approval), not contractual terms.
     *
     * @param  array<string, mixed>  $contentJson
     * @return array<string, mixed>
     */
    private function seedTermsJson(array $contentJson): array
    {
        return [
            'terms' => $contentJson['terms'] ?? null,
            'exclusions' => $contentJson['exclusions'] ?? null,
            'timeline' => $contentJson['timeline'] ?? null,
            'payment_plan' => $contentJson['payment_plan'] ?? null,
            'warranty_period' => null,
            'cancellation_policy' => null,
        ];
    }
}
