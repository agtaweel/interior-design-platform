<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Project;
use App\Models\Room;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET/POST /projects/{project}/rooms. {project} follows the same manual-lookup convention as
 * BoqController/BoqCategoryController/BoqItemController (see those docblocks / routes/api.php
 * for why implicit route-model binding is unsafe here). Both GET and POST require
 * Permissions::MANAGE_BOQ — POST checked inside StoreRoomRequest::authorize(), GET via an
 * explicit Gate::authorize() call (no FormRequest exists for this route). PROJECT_CONTEXT.md
 * Sprint 8 "Permissions hardening": index() used to require only an active membership; rooms
 * only exist to organize BOQ line items (see below), so — even though a room itself carries no
 * cost data — it's hardened for consistency with the rest of the BOQ-adjacent read surface
 * rather than leaving one endpoint on the looser rule.
 *
 * Before this controller existed, the only code path that created a Room row was
 * BoqCsvImporter's find-or-create-by-name during CSV import — a designer building a BOQ from
 * scratch (not via CSV import) had no way to add a room, which blocked the PRD's "Rooms: filter
 * line items by room" requirement for the BOQ Builder screen (S07).
 */
class RoomController extends Controller
{
    public function index(string $project): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_BOQ);

        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $rooms = Room::query()
            ->where('project_id', $projectModel->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => RoomResource::collection($rooms),
        ]);
    }

    public function store(StoreRoomRequest $request, string $project): JsonResponse
    {
        $projectModel = Project::find($project);

        if (! $projectModel) {
            return $this->notFound();
        }

        $room = Room::create([
            ...$request->validated(),
            'project_id' => $projectModel->id,
        ]);

        // fresh(): sort_order has a database-level default (0) that Eloquent doesn't reflect
        // on the in-memory instance when the field is omitted from the request — same
        // rationale as BoqCategoryController::store()'s fresh() call.
        return response()->json([
            'data' => new RoomResource($room->fresh()),
        ], 201);
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
