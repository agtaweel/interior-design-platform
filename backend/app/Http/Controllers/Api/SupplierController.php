<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Models\SupplierPriceHistory;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET/POST /suppliers, GET/PATCH /suppliers/{supplier}, GET /suppliers/{supplier}/price-history
 * (BRD "Procurement & Supplier Intelligence": supplier directory).
 *
 * {supplier} follows the same manual-lookup convention as every other cross-cutting controller
 * in this codebase — Supplier::find() is already org-scoped via BelongsToOrganization's global
 * scope. Every endpoint here (reads included) requires Permissions::MANAGE_PROCUREMENT — see
 * that constant's docblock for why supplier/pricing data is gated even for reads, unlike most
 * of this codebase's default "active membership is enough for GET" posture.
 */
class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROCUREMENT);

        $suppliers = Supplier::query()
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 50));

        return SupplierResource::collection($suppliers)->response();
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create([
            ...$request->validated(),
            'organization_id' => app(TenantContext::class)->organizationId(),
        ]);

        return response()->json(['data' => new SupplierResource($supplier)], 201);
    }

    public function show(string $supplier): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROCUREMENT);

        $model = Supplier::find($supplier);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json(['data' => new SupplierResource($model)]);
    }

    public function update(UpdateSupplierRequest $request, string $supplier): JsonResponse
    {
        $model = Supplier::find($supplier);

        if (! $model) {
            return $this->notFound();
        }

        $model->update($request->validated());

        return response()->json(['data' => new SupplierResource($model)]);
    }

    /**
     * BRD "Maintain supplier/product price history with date and source" — read surface for
     * SupplierPriceHistory::record()'s output (see PurchaseOrderController::send()/receive()).
     */
    public function priceHistory(string $supplier): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROCUREMENT);

        $model = Supplier::find($supplier);

        if (! $model) {
            return $this->notFound();
        }

        $history = SupplierPriceHistory::query()
            ->where('supplier_id', $model->id)
            ->orderByDesc('recorded_at')
            ->get();

        return response()->json([
            'data' => $history->map(fn ($row) => [
                'id' => $row->id,
                'item_description' => $row->item_description,
                'unit' => $row->unit,
                'unit_price' => $row->unit_price,
                'source' => $row->source,
                'recorded_at' => $row->recorded_at,
            ]),
        ]);
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
