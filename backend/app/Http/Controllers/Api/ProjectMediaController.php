<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectMediaRequest;
use App\Http\Resources\ProjectMediaResource;
use App\Models\Project;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET/POST /projects/{project}/media, DELETE /media/{media}, GET /media/{media}/file
 * (Documents tab attachments — designs, process photos, final pictures; see
 * Project::MEDIA_COLLECTIONS).
 *
 * {project} follows the same manual-lookup convention as every other project-nested controller
 * in this codebase (see routes/api.php's top docblock) — Project::find() is already
 * org-scoped via BelongsToOrganization's global scope.
 *
 * {media}, by contrast, is Spatie's own Media model — polymorphic (model_type/model_id) and
 * carrying no organization_id of its own. resolveTenantScopedMedia() re-derives tenant safety
 * by confirming the owning row actually IS a Project (never any other model type this app might
 * attach media to later) and that Project::find() resolves it under the current tenant scope —
 * same "walk back to an org-scoped model" pattern as PaymentController::resolveTenantScopedSchedule().
 *
 * Permission split: index()/show()/galleryIndex() (reads) require only an active membership,
 * matching ProjectController::show()'s own convention (design/progress files are not financial
 * data); store()/destroy() (mutations) are gated behind Permissions::MANAGE_PROJECTS — store()
 * inside StoreProjectMediaRequest, destroy() via an explicit Gate::authorize() call (no
 * FormRequest exists for a body-less DELETE, matching BoqItemController::destroy()'s pattern).
 */
class ProjectMediaController extends Controller
{
    /**
     * GET /media — the org-wide "Media" gallery (all attachments across every project in the
     * current tenant, not just one project's Documents tab). `Project::query()` is already
     * org-scoped via BelongsToOrganization's global scope, so constraining Media rows to only
     * those projects' ids is what makes this endpoint tenant-safe — same "intersect against an
     * org-scoped id set" approach as the {media} routes' resolveTenantScopedMedia(), just
     * applied to a list instead of one row. Optional `collection`/`project_id` query params
     * narrow the gallery client-side filters; `project_id` is redundantly re-intersected with
     * the org-scoped id list rather than trusted alone, so a foreign project id just yields an
     * empty page instead of leaking another organization's files.
     */
    public function galleryIndex(Request $request): JsonResponse
    {
        $projectIds = Project::query()->pluck('id');

        $query = Media::query()
            ->where('model_type', Project::class)
            ->whereIn('model_id', $projectIds)
            ->with('model:id,name,code');

        if ($collection = $request->query('collection')) {
            $query->where('collection_name', $collection);
        }

        if ($projectId = $request->query('project_id')) {
            $query->where('model_id', $projectId);
        }

        $media = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 100));

        return ProjectMediaResource::collection($media)->response();
    }

    public function index(string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        // getMedia() defaults to the (unused, non-existent here) "default" collection — this
        // model only ever uses the three named collections in Project::MEDIA_COLLECTIONS, so
        // the raw `media` relation (unfiltered by collection) is what actually returns
        // everything attached to this project.
        $media = $projectModel->media()->orderByDesc('created_at')->get();

        return response()->json([
            'data' => ProjectMediaResource::collection($media),
        ]);
    }

    public function store(StoreProjectMediaRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $data = $request->validated();

        $media = $projectModel
            ->addMedia($request->file('file'))
            ->withCustomProperties([
                'caption' => $data['caption'] ?? null,
                'uploaded_by' => $request->user()->name,
            ])
            ->toMediaCollection($data['collection']);

        return response()->json([
            'data' => new ProjectMediaResource($media),
        ], 201);
    }

    /**
     * `toInlineResponse()` (Content-Disposition: inline) rather than `toResponse()` (forces
     * "attachment") — lets an image render directly in an `<img>` tag / open in a new tab as a
     * preview; the frontend's own download button still forces a save via the anchor's
     * `download` attribute regardless of this header (see lib/api/resources/media.ts).
     */
    public function show(Request $request, string $media): StreamedResponse|JsonResponse
    {
        $mediaModel = $this->resolveTenantScopedMedia($media);

        if (! $mediaModel) {
            return $this->notFound();
        }

        return $mediaModel->toInlineResponse($request);
    }

    public function destroy(string $media): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROJECTS);

        $mediaModel = $this->resolveTenantScopedMedia($media);

        if (! $mediaModel) {
            return $this->notFound();
        }

        $mediaModel->delete();

        return response()->json(status: 204);
    }

    private function resolveTenantScopedMedia(string $media): ?Media
    {
        $model = Media::query()->find($media);

        if (! $model || $model->model_type !== Project::class) {
            return null;
        }

        if (! Project::find($model->model_id)) {
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
