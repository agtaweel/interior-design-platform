<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /public/marketplace/organizations/{organization}/media/{media}/file (BRD v4 "Client
 * Marketplace") — the first genuinely public, unauthenticated media route in this codebase.
 *
 * Spatie's `media` table is ONE polymorphic table shared by every HasMedia model — today that's
 * both `Project` (private design files, `designs`/`process`/`final_pictures` collections) and
 * `Organization` (public `portfolio` collection). This controller is the ONLY thing standing
 * between "public marketplace photo" and "someone's private project design file sharing the
 * same table." It therefore NEVER does a bare `Media::find($id)` — every lookup below is scoped
 * through `$organization->media()`, filtered to the `portfolio` collection, on an organization
 * that has actually opted into the marketplace. A bare `Media::find()` here would let an
 * anonymous caller fetch any media row in the system by ID, private project files included —
 * see tests/Feature/Marketplace/OrganizationPortfolioMediaLeakTest.php for the regression test
 * that exists specifically to catch a future accidental shortcut here.
 */
class PublicOrganizationMediaController extends Controller
{
    public function show(Request $request, string $organization, string $media): StreamedResponse|JsonResponse
    {
        $organizationModel = Organization::query()
            ->whereHas('profile', fn ($q) => $q->where('is_marketplace_listed', true))
            ->find($organization);

        if (! $organizationModel) {
            return $this->notFound();
        }

        $mediaModel = $organizationModel->media()
            ->where('collection_name', 'portfolio')
            ->find($media);

        if (! $mediaModel) {
            return $this->notFound();
        }

        return $mediaModel->toInlineResponse($request);
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
