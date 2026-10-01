<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationPortfolioMediaRequest;
use App\Models\Organization;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * GET/POST /organizations/{organization}/portfolio, DELETE .../portfolio/{media}
 * (BRD v4 "Client Marketplace" — staff-side manage for the public marketplace portfolio).
 * Tenant-scoped, mirrors ProjectMediaController's index()/store()/destroy() shape. The PUBLIC
 * read side of this same collection (the actual file bytes) lives in
 * PublicOrganizationMediaController, deliberately a separate controller with its own strict
 * containment rule — see that class's docblock. index() only needs an active membership (read),
 * matching ProjectMediaController's own read/write permission split; store()/destroy() require
 * Permissions::MANAGE_ORGANIZATION.
 */
class OrganizationPortfolioMediaController extends Controller
{
    public function index(string $organization): JsonResponse
    {
        $organizationModel = Organization::find($organization);

        if (! $organizationModel) {
            return $this->notFound();
        }

        $media = $organizationModel->media()->where('collection_name', 'portfolio')
            ->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $media->map(fn (Media $m) => [
                'id' => $m->id,
                'file_name' => $m->file_name,
                'mime_type' => $m->mime_type,
            ]),
        ]);
    }

    public function store(StoreOrganizationPortfolioMediaRequest $request, string $organization): JsonResponse
    {
        $organizationModel = Organization::find($organization);

        if (! $organizationModel) {
            return $this->notFound();
        }

        $media = $organizationModel
            ->addMedia($request->file('file'))
            ->toMediaCollection('portfolio');

        return response()->json([
            'data' => [
                'id' => $media->id,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
            ],
        ], 201);
    }

    public function destroy(string $organization, string $media): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_ORGANIZATION);

        $organizationModel = Organization::find($organization);

        if (! $organizationModel) {
            return $this->notFound();
        }

        $mediaModel = Media::query()
            ->where('model_type', Organization::class)
            ->where('model_id', $organizationModel->id)
            ->where('collection_name', 'portfolio')
            ->find($media);

        if (! $mediaModel) {
            return $this->notFound();
        }

        $mediaModel->delete();

        return response()->json(status: 204);
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
