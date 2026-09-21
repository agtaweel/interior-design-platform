<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Project;
use App\Models\Room;
use Illuminate\Http\JsonResponse;

/**
 * GET/POST /projects/{project}/rooms. {project} follows the same manual-lookup convention as
 * BoqController/BoqCategoryController/BoqItemController (see those docblocks / routes/api.php
 * for why implicit route-model binding is unsafe here). GET requires only an active membership
 * (read, matching GET /projects/{project}/boq); POST requires Permissions::MANAGE_BOQ, checked
 * inside StoreRoomRequest::authorize().
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
