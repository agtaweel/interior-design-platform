<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\IdempotencyKey;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Services\Payments\PaymentRecordingService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * POST/GET /payment-schedules/{schedule}/payments, GET /payments/{payment}/receipt
 * (PROJECT_CONTEXT.md Sprint 6).
 *
 * {schedule} follows the same manual-lookup convention as every other cross-cutting controller
 * in this codebase — PaymentSchedule carries no organization_id of its own (three-hop indirect,
 * see that model's docblock), so resolveTenantScopedSchedule() walks contract_id ->
 * contracts.project_id -> projects.organization_id via the OrganizationScope-guarded
 * Project::find(), one hop further than ContractController's own resolver.
 *
 * {payment}, by contrast, needs NO manual re-resolution: Payment uses BelongsToOrganization
 * directly (organization_id column of its own), so Payment::query()->find() is ALREADY
 * tenant-scoped by the OrganizationScope global scope that trait registers — same reasoning as
 * ProjectController::show() calling Project::find() with no extra check.
 *
 * Permission split, per PROJECT_CONTEXT.md: store() (mutation) is gated behind
 * Permissions::MANAGE_BOQ inside StorePaymentRequest; index()/receipt() (reads) are gated
 * behind Permissions::VIEW_FINANCIALS via explicit Gate::authorize() calls, matching
 * PaymentScheduleController::index()'s identical reasoning.
 */
class PaymentController extends Controller
{
    private const IDEMPOTENCY_SCOPE_PREFIX = 'payment_create';

    public function __construct(private readonly PaymentRecordingService $recordingService) {}

    /**
     * ## Idempotency-key replay (PROJECT_CONTEXT.md: "implement the EXACT same replay
     * semantics as Sprint 4's proposal-approval endpoint")
     *
     * Mirrors PublicProposalController::approve() precisely: an `Idempotency-Key` header that
     * matches a row already stored under this schedule's `"payment_create:{id}"` scope replays
     * the stored response_status/response_body VERBATIM and unconditionally — no
     * re-validation, no re-running PaymentRecordingService, no second Payment row created. A
     * header that's absent, or present but not yet seen for this scope, runs the full normal
     * flow and (only on success) stores its response for future replays. A failed
     * attempt is never stored, so a genuine retry with the same key can still succeed — same
     * "only successful mutations are recorded" rule Sprint 4 established.
     *
     * Scope string is exactly `"payment_create:{payment_schedule_id}"` per
     * PROJECT_CONTEXT.md's own example — namespaced per schedule (not per contract/project)
     * since that's the resource this endpoint mutates against.
     *
     * As with Sprint 4's ApprovePublicProposalRequest, Laravel validates the injected
     * StorePaymentRequest BEFORE this method body runs at all — see that class's docblock for
     * why a malformed replay body still 422s before reaching the replay check below, and why
     * that is the deliberately-mirrored behavior, not a gap in it.
     */
    public function store(StorePaymentRequest $request, string $schedule): JsonResponse
    {
        $scheduleModel = $this->resolveTenantScopedSchedule($schedule);

        if (! $scheduleModel) {
            return $this->notFound();
        }

        $scope = self::IDEMPOTENCY_SCOPE_PREFIX.":{$scheduleModel->id}";
        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey) {
            $stored = IdempotencyKey::query()->where('scope', $scope)->where('key', $idempotencyKey)->first();

            if ($stored) {
                return response()->json($stored->response_body, $stored->response_status);
            }
        }

        $payment = $this->recordingService->record($scheduleModel, $request->validated(), $request->file('receipt'));

        $body = ['data' => (new PaymentResource($payment))->toArray($request)];

        // Only a genuinely NEW successful request stores an idempotency record — a replay
        // never reaches here (it returned above), matching PublicProposalController::approve().
        if ($idempotencyKey) {
            IdempotencyKey::create([
                'scope' => $scope,
                'key' => $idempotencyKey,
                'response_status' => 201,
                'response_body' => $body,
            ]);
        }

        return response()->json($body, 201);
    }

    public function index(string $schedule): JsonResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        $scheduleModel = $this->resolveTenantScopedSchedule($schedule);

        if (! $scheduleModel) {
            return $this->notFound();
        }

        $payments = Payment::query()
            ->where('payment_schedule_id', $scheduleModel->id)
            ->orderByDesc('paid_at')
            ->get();

        return response()->json([
            'data' => PaymentResource::collection($payments),
        ]);
    }

    /**
     * Streams the receipt file back with the correct content-type inferred from the stored
     * file itself (Storage::response() delegates to Symfony's MIME guesser against the actual
     * file on disk, not a trusted client-supplied header). 404 if the payment doesn't exist
     * (or belongs to another tenant — Payment::find() is already org-scoped, see class
     * docblock), has no receipt, or the file is unexpectedly missing from disk.
     *
     * This route IS the "signed URL" in spirit for this dev environment (PROJECT_CONTEXT.md):
     * in production, `receipt_url` would be an S3-compatible object key and this same route
     * would issue/redirect to a short-lived, real pre-signed URL instead of streaming bytes
     * through the app server directly. The client never sees the raw storage path either way —
     * only ever this authenticated, tenant-checked, permission-gated indirection.
     */
    public function receipt(Request $request, string $payment): StreamedResponse|JsonResponse
    {
        Gate::authorize(Permissions::VIEW_FINANCIALS);

        $paymentModel = Payment::query()->find($payment);

        if (! $paymentModel || ! $paymentModel->receipt_url) {
            return $this->notFound();
        }

        if (! Storage::disk('local')->exists($paymentModel->receipt_url)) {
            return $this->notFound();
        }

        return Storage::disk('local')->response($paymentModel->receipt_url);
    }

    /**
     * Fetches a PaymentSchedule by id (un-scoped, since the model has no organization_id of
     * its own at all — three-hop indirect, see class docblock) and confirms it belongs to the
     * current tenant by re-resolving its contract's project through the
     * OrganizationScope-guarded Project::find(). The null-safe `?->` protects against a
     * data-integrity edge case (a schedule whose contract_id doesn't resolve) by falling
     * through to the same 404 as a genuine cross-tenant record, never a 500.
     */
    private function resolveTenantScopedSchedule(string $schedule): ?PaymentSchedule
    {
        $model = PaymentSchedule::query()->find($schedule);

        if (! $model) {
            return null;
        }

        if (! Project::find($model->contract?->project_id)) {
            return null;
        }

        return $model;
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
                'details' => (object) [],
            ],
        ], 404);
    }
}
