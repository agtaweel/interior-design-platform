<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexClientsRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * All four endpoints run behind `auth:sanctum` + `tenant` (see routes/api.php), so
 * App\Models\Scopes\OrganizationScope already confines every Client query below to the
 * current organization — a wrong-tenant {client} id simply resolves to null, surfaced here as
 * a normal 404, not a leak. Reads (index/show) require no extra permission beyond an active
 * membership; writes (store/update) are gated inside the respective Form Requests via
 * Permissions::MANAGE_CLIENTS (checked in authorize() before validation runs).
 */
class ClientController extends Controller
{
    public function index(IndexClientsRequest $request): JsonResponse
    {
        $query = Client::query()->withCount(['properties', 'projects']);

        if ($search = $request->validated('q')) {
            $needle = mb_strtolower($search);

            // whereRaw + LOWER() rather than `ilike` so this works identically on the
            // Postgres connection used in dev/prod and the sqlite connection used by the test
            // suite (see phpunit.xml) — `ilike` is Postgres-only.
            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(phone) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$needle}%"]);
            });
        }

        $clients = $query->orderBy('name')->paginate($request->integer('per_page', 20));

        return ClientResource::collection($clients)->response();
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = Client::create([
            ...$request->validated(),
            // Never trust a client-supplied organization_id — always the resolved tenant.
            // (BelongsToOrganization's saving guard would also auto-fill this, but we set it
            // explicitly here per the project's tenant-safety convention: don't rely solely
            // on the model-level defensive guard.)
            'organization_id' => app(TenantContext::class)->organizationId(),
        ]);

        return response()->json([
            'data' => new ClientResource($client),
        ], 201);
    }

    public function show(string $client): JsonResponse
    {
        $model = Client::query()
            ->withCount(['properties', 'projects'])
            ->with(['properties', 'projects'])
            ->find($client);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json([
            'data' => new ClientResource($model),
        ]);
    }

    public function update(UpdateClientRequest $request, string $client): JsonResponse
    {
        $model = Client::find($client);

        if (! $model) {
            return $this->notFound();
        }

        $model->update($request->validated());

        return response()->json([
            'data' => new ClientResource($model->fresh()),
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
