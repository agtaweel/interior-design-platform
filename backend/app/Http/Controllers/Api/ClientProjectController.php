<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ProjectMediaResource;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProposalVersion;
use App\Services\Marketplace\ClientOwnershipResolver;
use App\Services\Payments\ProjectFinancialsCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /client/projects[/{project}[/contract|payments|change-orders|media[/{media}/file]]]
 * (BRD v4 "Client Marketplace" Phase C) — a logged-in-client equivalent of
 * PublicClientPortalController, method-by-method: same redaction rules (value/collected/
 * outstanding only, never cost/margin/quoted_cost/committed_cost/actual_cost — see that
 * class's docblock for the full list of what's never exposed), same client-safe resources
 * (ContractResource/PaymentResource/ProjectMediaResource), same "no Purchase Orders/Invoice
 * Documents/Expenses route exists here at all" posture. The only thing that differs is the
 * resolution front door: ClientOwnershipResolver (an authenticated ClientUser's own projects)
 * instead of a signed public token.
 */
class ClientProjectController extends Controller
{
    public function __construct(
        private readonly ClientOwnershipResolver $resolver,
        private readonly ProjectFinancialsCalculator $financialsCalculator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var ClientUser $clientUser */
        $clientUser = $request->user();
        $projects = $this->resolver->projectsFor($clientUser);

        return response()->json([
            'data' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'status' => $p->status,
            ])->values(),
        ]);
    }

    public function show(Request $request, string $project): JsonResponse
    {
        $model = $this->resolveProject($request, $project);

        if ($model instanceof JsonResponse) {
            return $model;
        }

        $financials = $this->financialsCalculator->calculate($model);

        return response()->json([
            'data' => [
                'project' => [
                    'code' => $model->code,
                    'name' => $model->name,
                    'status' => $model->status,
                    'start_date' => $model->start_date,
                    'target_end_date' => $model->target_end_date,
                ],
                'organization' => ['currency' => $model->organization->currency],
                'financials' => [
                    'value' => $model->grand_total ?? 0,
                    'collected' => $financials['collected'],
                    'outstanding' => $financials['outstanding'],
                ],
                'counts' => [
                    'proposals' => ProposalVersion::query()->where('project_id', $model->id)->where('status', '!=', 'draft')->count(),
                    'change_orders' => ChangeOrder::query()->where('project_id', $model->id)->where('status', '!=', 'draft')->count(),
                    'payments' => Payment::query()->where('project_id', $model->id)->count(),
                ],
            ],
        ]);
    }

    public function contract(Request $request, string $project): JsonResponse
    {
        $model = $this->resolveProject($request, $project);

        if ($model instanceof JsonResponse) {
            return $model;
        }

        $contract = Contract::query()
            ->where('project_id', $model->id)
            ->with(['project.client', 'proposalVersion'])
            ->latest('signed_at')
            ->first();

        if (! $contract) {
            return $this->notFound();
        }

        return response()->json([
            'data' => new ContractResource($contract),
            'organization' => ['currency' => $model->organization->currency],
        ]);
    }

    public function payments(Request $request, string $project): JsonResponse
    {
        $model = $this->resolveProject($request, $project);

        if ($model instanceof JsonResponse) {
            return $model;
        }

        $payments = Payment::query()->where('project_id', $model->id)->orderByDesc('paid_at')->get();

        return response()->json([
            'data' => PaymentResource::collection($payments),
            'organization' => ['currency' => $model->organization->currency],
        ]);
    }

    public function changeOrders(Request $request, string $project): JsonResponse
    {
        $model = $this->resolveProject($request, $project);

        if ($model instanceof JsonResponse) {
            return $model;
        }

        $orders = ChangeOrder::query()
            ->where('project_id', $model->id)
            ->where('status', '!=', 'draft')
            ->with('items')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (ChangeOrder $order) => [
                'number' => $order->number,
                'status' => $order->status,
                'reason' => $order->reason,
                'timeline_delta_days' => $order->timeline_delta_days,
                'price_delta' => $order->price_delta,
                'items' => $order->items->map(fn (ChangeOrderItem $item): array => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'old_unit_price' => $item->old_unit_price,
                    'new_unit_price' => $item->new_unit_price,
                    'line_delta' => $item->line_delta,
                ])->values(),
                'sent_at' => $order->sent_at,
                'approved_at' => $order->approved_at,
            ])->values(),
            'organization' => ['currency' => $model->organization->currency],
        ]);
    }

    public function media(Request $request, string $project): JsonResponse
    {
        $model = $this->resolveProject($request, $project);

        if ($model instanceof JsonResponse) {
            return $model;
        }

        $media = $model->media()->orderByDesc('created_at')->get();

        return response()->json(['data' => ProjectMediaResource::collection($media)]);
    }

    public function mediaFile(Request $request, string $project, string $media): StreamedResponse|JsonResponse
    {
        $model = $this->resolveProject($request, $project);

        if ($model instanceof JsonResponse) {
            return $model;
        }

        $mediaModel = $model->media()->where('id', $media)->first();

        if (! $mediaModel) {
            return $this->notFound();
        }

        return $mediaModel->toInlineResponse($request);
    }

    private function resolveProject(Request $request, string $project): Project|JsonResponse
    {
        /** @var ClientUser $clientUser */
        $clientUser = $request->user();
        $model = $this->resolver->resolveProject($clientUser, $project);

        return $model ?? $this->notFound();
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
