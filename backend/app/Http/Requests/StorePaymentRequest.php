<?php

namespace App\Http\Requests;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payment-schedules/{schedule}/payments. Multipart body (per the optional `receipt` file
 * field) — payment_schedule_id/organization_id/project_id are never accepted from the body,
 * they're derived server-side from the route's schedule (see PaymentRecordingService).
 * Mutation gated behind Permissions::MANAGE_BOQ, same convention as
 * StorePaymentScheduleRequest.
 *
 * NOTE on idempotency-replay interaction (PROJECT_CONTEXT.md: "implement the EXACT same replay
 * semantics as Sprint 4's proposal-approval endpoint"): Laravel resolves and validates a
 * type-hinted FormRequest BEFORE the controller method body ever runs. This means a replayed
 * request with a matching Idempotency-Key header but a malformed/incomplete second body is
 * REJECTED here with 422 before PaymentController::store() gets a chance to short-circuit into
 * the replay path — this is not a bug, it's the exact same boundary Sprint 4's
 * ApprovePublicProposalRequest has (see
 * tests/Feature/Proposals/ProposalApprovalTest::test_replay_does_not_require_a_body_at_all...
 * for the precedent this mirrors verbatim). A replay only skips re-running the BUSINESS logic
 * (state checks, side effects); it never skips Laravel's request-validation gate, which sits
 * upstream of any controller code on both endpoints alike.
 *
 * receipt: image or PDF, capped at 10MB. `mimes` validates the actual file content (via
 * Symfony's MIME guesser), not just the client-supplied extension/Content-Type header, per
 * PROJECT_CONTEXT.md's "validate mime type, don't just trust the extension" instruction.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::MANAGE_BOQ);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', 'max:100'],
            'paid_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
        ];
    }
}
