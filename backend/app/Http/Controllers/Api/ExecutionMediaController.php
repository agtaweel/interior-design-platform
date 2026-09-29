<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\SiteReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /execution-media/{media}/file — streams a ProjectTask or SiteReport photo. A single
 * shared endpoint for both (rather than duplicating ProjectMediaController's whole
 * show()/resolveTenantScopedMedia() pair twice more) since the only difference between the two
 * is which model type owns the Media row; both resolve to a project the same way.
 *
 * See ProjectMediaController::resolveTenantScopedMedia() for the identical "walk back to an
 * org-scoped model" tenant-safety pattern this mirrors — that one is hardcoded to
 * Project::class only, this one accepts either ProjectTask or SiteReport instead.
 */
class ExecutionMediaController extends Controller
{
    private const OWNING_MODELS = [ProjectTask::class, SiteReport::class];

    public function show(Request $request, string $media): StreamedResponse|JsonResponse
    {
        $model = Media::query()->find($media);

        if (! $model || ! in_array($model->model_type, self::OWNING_MODELS, true)) {
            return $this->notFound();
        }

        /** @var ProjectTask|SiteReport|null $owner */
        $owner = $model->model_type::find($model->model_id);

        if (! $owner || ! Project::find($owner->project_id)) {
            return $this->notFound();
        }

        return $model->toInlineResponse($request);
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
