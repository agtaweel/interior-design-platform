<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationProfileRequest;
use App\Models\Organization;
use App\Services\Marketplace\OrganizationProfileService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * GET/PATCH /organizations/{organization}/profile (BRD v4 "Client Marketplace", staff-side
 * settings half). {organization} follows the same manual-lookup convention as every other
 * tenant-scoped controller in this codebase — see routes/api.php's top docblock.
 */
class OrganizationProfileController extends Controller
{
    public function __construct(private readonly OrganizationProfileService $service) {}

    public function show(string $organization): JsonResponse
    {
        $organizationModel = Organization::find($organization);

        if (! $organizationModel) {
            return $this->notFound();
        }

        $profile = $organizationModel->profile;

        return response()->json([
            'data' => $profile ? [
                'description' => $profile->description,
                'services_offered' => $profile->services_offered ?? [],
                'service_area' => $profile->service_area,
                'is_marketplace_listed' => $profile->is_marketplace_listed,
            ] : [
                'description' => null,
                'services_offered' => [],
                'service_area' => null,
                'is_marketplace_listed' => false,
            ],
        ]);
    }

    public function update(UpdateOrganizationProfileRequest $request, string $organization): JsonResponse
    {
        $organizationModel = Organization::find($organization);

        if (! $organizationModel) {
            return $this->notFound();
        }

        $data = $request->validated();

        try {
            if (array_key_exists('is_marketplace_listed', $data)) {
                $this->service->updateProfile($organizationModel, collect($data)->except('is_marketplace_listed')->all());
                $profile = $this->service->setListed($organizationModel, (bool) $data['is_marketplace_listed']);
            } else {
                $profile = $this->service->updateProfile($organizationModel, $data);
            }
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'marketplace_listing_requires_description',
                    'message' => $e->getMessage(),
                    'details' => (object) [],
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                'description' => $profile->description,
                'services_offered' => $profile->services_offered ?? [],
                'service_area' => $profile->service_area,
                'is_marketplace_listed' => $profile->is_marketplace_listed,
            ],
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
