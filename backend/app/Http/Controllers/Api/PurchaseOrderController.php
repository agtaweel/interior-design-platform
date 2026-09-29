<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReceivePurchaseOrderRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierPriceHistory;
use App\Services\Finance\FinancialLedgerService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * GET/POST /projects/{project}/purchase-orders, GET /purchase-orders/{po},
 * POST /purchase-orders/{po}/send|receive|cancel (BRD "Procurement": PO lifecycle
 * draft -> sent -> partially_received -> received -> cancelled).
 *
 * {project} follows the same manual-lookup convention as every other project-nested
 * controller. {po} is PurchaseOrder — no organization_id of its own, resolved+tenant-checked
 * via resolveTenantScopedPo() by walking project_id -> the org-scoped Project::find(), same
 * pattern as ContractController/PaymentScheduleController.
 *
 * Every endpoint here (reads included) requires Permissions::MANAGE_PROCUREMENT — see that
 * constant's docblock.
 */
class PurchaseOrderController extends Controller
{
    private const RELATIONS = ['supplier', 'items'];

    public function __construct(private readonly FinancialLedgerService $ledger) {}

    public function index(string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROCUREMENT);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $orders = PurchaseOrder::query()
            ->where('project_id', $projectModel->id)
            ->with(self::RELATIONS)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => PurchaseOrderResource::collection($orders)]);
    }

    public function store(StorePurchaseOrderRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $data = $request->validated();

        $order = DB::transaction(function () use ($data, $projectModel) {
            $order = PurchaseOrder::create([
                'project_id' => $projectModel->id,
                'supplier_id' => $data['supplier_id'],
                'po_number' => $data['po_number'] ?? $this->generatePoNumber($projectModel),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'description' => $item['description'],
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'quoted_unit_price' => $item['quoted_unit_price'],
                ]);
            }

            return $order;
        });

        return response()->json([
            'data' => new PurchaseOrderResource($order->fresh(self::RELATIONS)),
        ], 201);
    }

    public function show(string $po): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROCUREMENT);

        $order = $this->resolveTenantScopedPo($po);

        if (! $order) {
            return $this->notFound();
        }

        return response()->json(['data' => new PurchaseOrderResource($order->load(self::RELATIONS))]);
    }

    /**
     * draft -> sent. Records each item's quoted price into SupplierPriceHistory (source
     * 'quoted') at the moment it's actually sent to the supplier, not at draft-creation time —
     * a draft can still be edited/discarded, so only a sent PO represents a real commitment
     * worth recording as historical pricing data.
     */
    public function send(string $po): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROCUREMENT);

        $order = $this->resolveTenantScopedPo($po);

        if (! $order) {
            return $this->notFound();
        }

        if ($order->status !== 'draft') {
            return $this->error(409, 'purchase_order_not_draft', 'Only a draft purchase order can be sent.');
        }

        DB::transaction(function () use ($order) {
            $order->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

            foreach ($order->items as $item) {
                SupplierPriceHistory::record($item, 'quoted', (string) $item->quoted_unit_price);
            }
        });

        return response()->json(['data' => new PurchaseOrderResource($order->fresh(self::RELATIONS))]);
    }

    /**
     * Records a (partial or full) delivery against one or more lines. Only valid from
     * 'sent'/'partially_received' — a draft PO hasn't been ordered yet, and a
     * 'received'/'cancelled' PO is terminal. After applying the given lines' received_quantity/
     * actual_unit_price, the PO's own status is recomputed: 'received' if every line's
     * received_quantity now meets its ordered quantity, otherwise 'partially_received'.
     * Each updated line's actual price is also recorded into SupplierPriceHistory (source
     * 'actual') — BRD "Record quoted price, ordered price and actual received cost separately."
     */
    public function receive(ReceivePurchaseOrderRequest $request, string $po): JsonResponse
    {
        $order = $this->resolveTenantScopedPo($po);

        if (! $order) {
            return $this->notFound();
        }

        if (! in_array($order->status, ['sent', 'partially_received'], true)) {
            return $this->error(
                409,
                'purchase_order_not_receivable',
                'Only a sent or partially received purchase order can record a delivery.',
            );
        }

        $itemIds = $order->items->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ($request->validated('items') as $line) {
            if (! in_array((string) $line['id'], $itemIds, true)) {
                return $this->error(422, 'validation_failed', 'One or more items do not belong to this purchase order.');
            }
        }

        DB::transaction(function () use ($order, $request) {
            $project = $order->project;

            foreach ($request->validated('items') as $line) {
                /** @var PurchaseOrderItem $item */
                $item = $order->items->firstWhere('id', $line['id']);
                $item->forceFill([
                    'received_quantity' => $line['received_quantity'],
                    'actual_unit_price' => $line['actual_unit_price'],
                ])->save();

                SupplierPriceHistory::record($item, 'actual', (string) $line['actual_unit_price']);

                // BRD v3 §4: a received PO line is a real cost commitment — posted per line
                // (not once per receive() call) so each ledger row traces to exactly one PO
                // item, matching source_entity_type/source_entity_id's one-row-per-source intent.
                $this->ledger->postFor($item, [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'scope' => FinancialTransaction::SCOPE_COST,
                    'type' => FinancialTransaction::TYPE_SUPPLIER_INVOICE,
                    'amount' => $item->actualTotal(),
                    'transaction_date' => now()->toDateString(),
                    'created_by' => auth()->id(),
                ]);
            }

            $order->refresh();
            $allReceived = $order->items->every(
                fn (PurchaseOrderItem $item) => bccomp((string) $item->received_quantity, (string) $item->quantity, 3) >= 0
            );

            $order->forceFill(['status' => $allReceived ? 'received' : 'partially_received'])->save();
        });

        return response()->json(['data' => new PurchaseOrderResource($order->fresh(self::RELATIONS))]);
    }

    public function cancel(string $po): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROCUREMENT);

        $order = $this->resolveTenantScopedPo($po);

        if (! $order) {
            return $this->notFound();
        }

        if ($order->status === 'received') {
            return $this->error(409, 'purchase_order_already_received', 'A fully received purchase order cannot be cancelled.');
        }

        if ($order->status === 'cancelled') {
            return $this->error(409, 'purchase_order_already_cancelled', 'This purchase order is already cancelled.');
        }

        $order->forceFill(['status' => 'cancelled'])->save();

        return response()->json(['data' => new PurchaseOrderResource($order->fresh(self::RELATIONS))]);
    }

    private function generatePoNumber(Project $project): string
    {
        $next = PurchaseOrder::query()->where('project_id', $project->id)->count() + 1;

        return 'PO-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Fetches a PurchaseOrder by id (un-scoped, since the model has no organization_id of its
     * own — see class docblock) and confirms it belongs to the current tenant by re-resolving
     * its project through the OrganizationScope-guarded Project::find(), same pattern as
     * ContractController::resolveTenantScopedContract().
     */
    private function resolveTenantScopedPo(string $po): ?PurchaseOrder
    {
        $model = PurchaseOrder::query()->with('items')->find($po);

        if (! $model) {
            return null;
        }

        if (! Project::find($model->project_id)) {
            return null;
        }

        return $model;
    }

    private function notFound(): JsonResponse
    {
        return $this->error(404, 'not_found', 'The requested resource was not found.');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) [],
            ],
        ], $status);
    }
}
