<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Client;
use App\Models\Property;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class PropertyController extends Controller
{
    /**
     * POST /clients/{client}/properties. $client is resolved manually (not via implicit route
     * model binding) and scoped by OrganizationScope, same pattern as ClientController — see
     * that controller's docblock for why implicit binding is avoided on tenant-scoped models.
     */
    public function store(StorePropertyRequest $request, string $client): JsonResponse
    {
        $clientModel = Client::find($client);

        if (! $clientModel) {
            return $this->notFound();
        }

        $property = Property::create([
            ...$request->validated(),
            'client_id' => $clientModel->id,
            // Never trust a client-supplied organization_id — always the resolved tenant.
            'organization_id' => app(TenantContext::class)->organizationId(),
        ]);

        // fresh(): metadata_json has a database-level default ('{}') that Eloquent doesn't
        // reflect on the in-memory instance when the field is omitted from the request — a
        // re-fetch shows the caller what was actually persisted.
        return response()->json([
            'data' => new PropertyResource($property->fresh()),
        ], 201);
    }

    public function show(string $property): JsonResponse
    {
        $model = Property::query()
            ->withCount('projects')
            ->with(['client', 'projects'])
            ->find($property);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json([
            'data' => new PropertyResource($model),
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
