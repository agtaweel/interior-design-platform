<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovePublicProposalRequest;
use App\Http\Requests\RequestChangesPublicProposalRequest;
use App\Http\Resources\PublicProposalResource;
use App\Models\Approval;
use App\Models\IdempotencyKey;
use App\Models\OtpChallenge;
use App\Models\ProposalVersion;
use App\Models\SignedLink;
use App\Services\Proposals\OtpChallengeService;
use App\Services\Proposals\OtpVerificationException;
use App\Services\Proposals\ProposalPresenter;
use App\Support\PublicLinks\SignedLinkException;
use App\Support\PublicLinks\SignedLinkService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * GET /public/proposals/{token}, POST /public/proposals/{token}/approve, POST
 * /public/proposals/{token}/request-changes, GET /public/proposals/{token}/pdf.
 *
 * NO auth:sanctum, NO `tenant` middleware — these routes sit outside both groups entirely (see
 * routes/api.php). Token-authenticated instead: every action starts by resolving the token via
 * SignedLinkService::verify(), which is the sole gate (not expired/revoked, correct purpose
 * 'proposal_approval'). All four routes carry the `public-links` rate limiter
 * (AppServiceProvider::registerRateLimiters()) since this is, by design, an enumerable-by-token
 * public surface.
 *
 * Never exposes cost/margin/source_boq_item_id — every response here is built either through
 * PublicProposalResource (wraps ProposalPresenter's already-scrubbed payload) or hand-built
 * JSON containing nothing but {status, approved_at, approval_id, contract_conversion_available}
 * / {status} for the mutation endpoints.
 */
class PublicProposalController extends Controller
{
    public function __construct(
        private readonly SignedLinkService $signedLinkService,
        private readonly OtpChallengeService $otpService,
        private readonly ProposalPresenter $presenter,
    ) {}

    private const PURPOSE = 'proposal_approval';

    public function show(string $token): JsonResponse
    {
        $version = $this->resolveVersionFromToken($token);

        if ($version instanceof JsonResponse) {
            return $version;
        }

        return response()->json([
            'data' => new PublicProposalResource($this->presenter->present($version)),
        ]);
    }

    public function pdf(string $token): HttpResponse|JsonResponse
    {
        $version = $this->resolveVersionFromToken($token);

        if ($version instanceof JsonResponse) {
            return $version;
        }

        $payload = $this->presenter->present($version);
        // Re-shape through the same array PublicProposalResource exposes (grand_total only)
        // rather than passing the raw presenter payload (which still carries the full
        // subtotal/markup/fees/discount breakdown for the internal detail view) — the Blade
        // view must never receive that breakdown, so we scrub it here at the same boundary the
        // JSON endpoint uses.
        $clientPayload = (new PublicProposalResource($payload))->toArray(request());
        $filename = sprintf('proposal-%s-v%d.pdf', $payload['project']['code'] ?? $version->project_id, $version->version_no);

        return Pdf::loadView('proposals.pdf', ['data' => $clientPayload])->download($filename);
    }

    /**
     * ## Idempotency-key reconciliation (PROJECT_CONTEXT.md Sprint 4 — the two
     * "seemingly-contradictory" requirements reconciled)
     *
     * The NFR says money-moving POSTs must be idempotent; the PRD's §3.3 example says
     * approving an already-approved version returns 409 PROPOSAL_ALREADY_APPROVED. Both are
     * true depending on ONE thing: whether this specific request carries an `Idempotency-Key`
     * header that matches a key already stored for THIS proposal version's approve scope.
     *
     *   - Header present AND matches a stored (scope, key) row -> replay the stored
     *     response_status/response_body verbatim, unconditionally. This is the one case where
     *     we do NOT re-validate the token/OTP/status at all — by definition, if a row is
     *     stored, this exact client already successfully passed every one of those checks
     *     once, and the whole point of an idempotency key is "if my first request's response
     *     got lost/timed out, retrying must not double-process or force me through OTP again."
     *   - Header absent, or present but NOT found in the idempotency_keys table (first attempt
     *     with that key, or no key sent at all) -> full normal validation, including the
     *     already-approved 409. This is what makes a genuinely repeated approve() call WITHOUT
     *     the original idempotency key (e.g., staff manually hits approve twice from two
     *     different client sessions with no header) correctly 409 rather than silently
     *     succeeding twice.
     *
     * We still resolve the token to a ProposalVersion BEFORE checking the idempotency store —
     * that is infrastructure needed to know WHICH scope to look under
     * ("proposal_approve:{proposal_version_id}"), not "re-validating" in the business sense;
     * only OTP correctness/attempts and the already-approved status check are skipped on a
     * genuine replay.
     */
    public function approve(ApprovePublicProposalRequest $request, string $token): JsonResponse
    {
        $version = $this->resolveVersionFromToken($token, forMutation: true);

        if ($version instanceof JsonResponse) {
            return $version;
        }

        $scope = "proposal_approve:{$version->id}";
        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey) {
            $stored = IdempotencyKey::query()->where('scope', $scope)->where('key', $idempotencyKey)->first();

            if ($stored) {
                return response()->json($stored->response_body, $stored->response_status);
            }
        }

        $link = SignedLink::query()->where('token_hash', hash('sha256', $token))->first();
        $challenge = $link ? OtpChallenge::query()->where('signed_link_id', $link->id)->latest('id')->first() : null;

        if (! $link || ! $challenge) {
            // Data-integrity fallback only — every 'sent' version gets exactly one signed
            // link + one otp_challenge in the same transaction (ProposalSendService::send()),
            // so this branch should be unreachable in practice.
            return $this->linkError();
        }

        try {
            $this->otpService->verify($challenge, $request->validated('otp'));
        } catch (OtpVerificationException $e) {
            return match ($e->reason) {
                'locked' => $this->error(422, 'OTP_LOCKED', 'Too many incorrect attempts. This proposal can no longer be approved from this link.'),
                'expired' => $this->error(422, 'OTP_EXPIRED', 'This verification code has expired.'),
                default => $this->error(422, 'OTP_INVALID', 'The verification code is incorrect.', ['attempts_remaining' => $e->attemptsRemaining]),
            };
        }

        // Checked AFTER OTP validation, per PROJECT_CONTEXT.md's literal ordering — see
        // OtpChallengeService::verify()'s docblock for why this order is deliberate (a wrong
        // OTP against an already-approved proposal still reports OTP_INVALID, not this).
        //
        // Kept UPPERCASE (unlike this codebase's usual lowercase snake_case error codes, e.g.
        // 'not_found', 'validation_failed') to match PRD §3.3's literal example verbatim —
        // PROJECT_CONTEXT.md quotes this exact code, so we reproduce it rather than
        // lowercasing it for internal-convention consistency.
        if ($version->status === 'approved') {
            return $this->error(409, 'PROPOSAL_ALREADY_APPROVED', 'This proposal has already been approved.');
        }

        // No dedicated "approver name" column on `approvals` (see migration/model — only
        // approver_type/user_id/comment/ip_address). Rather than silently dropping the
        // client-supplied `name`, we fold it into `comment` with a clear prefix so it's still
        // captured for audit/traceability — documented here rather than left unexplained.
        $comment = $this->composeComment($request->validated('name'), $request->validated('comment'));

        $body = DB::transaction(function () use ($version, $challenge, $comment, $request) {
            $approvedAt = now();

            $version->forceFill(['status' => 'approved', 'approved_at' => $approvedAt])->save();

            $approval = Approval::create([
                'organization_id' => $version->project->organization_id,
                'project_id' => $version->project_id,
                'entity_type' => ProposalVersion::ENTITY_TYPE,
                'entity_id' => $version->id,
                'approver_type' => 'client',
                'user_id' => null,
                'status' => 'approved',
                'comment' => $comment,
                'approved_at' => $approvedAt,
                'ip_address' => $request->ip(),
            ]);

            $challenge->forceFill(['verified_at' => $approvedAt])->save();

            return [
                'status' => 'approved',
                'approved_at' => $approvedAt->toJSON(),
                'approval_id' => $approval->id,
                'contract_conversion_available' => true,
            ];
        });

        $this->signedLinkService->markUsed($token);

        // Only a genuinely NEW successful request stores an idempotency record — a replay
        // never reaches here (it returned above), and a failed/409 response is deliberately
        // NOT stored (see class docblock: idempotency only protects the successful mutation,
        // not every possible response).
        if ($idempotencyKey) {
            IdempotencyKey::create([
                'scope' => $scope,
                'key' => $idempotencyKey,
                'response_status' => 200,
                'response_body' => $body,
            ]);
        }

        return response()->json($body, 200);
    }

    /**
     * No idempotency-replay logic here — PROJECT_CONTEXT.md explicitly calls this out as lower
     * stakes than approve() (a comment, not a binding commercial action) and doesn't require
     * it. Calling this twice is naturally near-idempotent in effect (status ends up
     * 'changes_requested' either way; the only side effect of a duplicate call is a second
     * `approvals` row, which is an acceptable/harmless audit-trail duplicate, not a financial
     * double-mutation) — documented here per the task's instruction to note this choice rather
     * than silently omitting replay support.
     *
     * Guard added beyond the literal spec: refuses (409 PROPOSAL_ALREADY_APPROVED) if the
     * version is already 'approved'. PROJECT_CONTEXT.md doesn't explicitly address this
     * interaction, but letting a request-changes call silently flip an approved (i.e.
     * potentially already-being-converted-to-a-contract) version back to 'changes_requested'
     * would undermine the whole point of approval being a locked, immutable state — treated as
     * the same conflict as trying to approve an already-approved version.
     */
    public function requestChanges(RequestChangesPublicProposalRequest $request, string $token): JsonResponse
    {
        $version = $this->resolveVersionFromToken($token, forMutation: true);

        if ($version instanceof JsonResponse) {
            return $version;
        }

        if ($version->status === 'approved') {
            return $this->error(409, 'PROPOSAL_ALREADY_APPROVED', 'This proposal has already been approved and can no longer be sent back for changes.');
        }

        $comment = $this->composeComment($request->validated('name'), $request->validated('comment'));

        DB::transaction(function () use ($version, $comment, $request) {
            $version->forceFill(['status' => 'changes_requested'])->save();

            Approval::create([
                'organization_id' => $version->project->organization_id,
                'project_id' => $version->project_id,
                'entity_type' => ProposalVersion::ENTITY_TYPE,
                'entity_id' => $version->id,
                'approver_type' => 'client',
                'user_id' => null,
                'status' => 'changes_requested',
                'comment' => $comment,
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'data' => [
                'status' => 'changes_requested',
            ],
        ]);
    }

    /**
     * Resolves a public token to its ProposalVersion, or returns the JsonResponse error to
     * short-circuit with. $forMutation additionally re-fetches with the `project` relation
     * eager-loaded (needed for organization_id/project_id on the approve/request-changes
     * writes) — show()/pdf() don't need it since ProposalPresenter loads its own relations.
     *
     * Token errors (invalid/expired/revoked) are all collapsed to the SAME 404 response
     * regardless of SignedLinkException::$reason — deliberately not distinguishing "expired"
     * from "never existed" to a public, unauthenticated caller (this is an enumerable-by-token
     * surface; a more specific error would help an attacker distinguish a guessed token that
     * once existed from one that never did).
     */
    private function resolveVersionFromToken(string $token, bool $forMutation = false): ProposalVersion|JsonResponse
    {
        try {
            $payload = $this->signedLinkService->verify($token, self::PURPOSE);
        } catch (SignedLinkException) {
            return $this->linkError();
        }

        $versionId = $payload['proposal_version_id'] ?? null;
        $version = $versionId
            ? ProposalVersion::query()->when($forMutation, fn ($q) => $q->with('project'))->find($versionId)
            : null;

        if (! $version) {
            return $this->linkError();
        }

        return $version;
    }

    private function composeComment(string $name, ?string $comment): string
    {
        return $comment ? "{$name}: {$comment}" : $name;
    }

    private function linkError(): JsonResponse
    {
        return $this->error(404, 'proposal_link_invalid', 'This proposal link is invalid, expired, or has been revoked.');
    }

    private function error(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }
}
