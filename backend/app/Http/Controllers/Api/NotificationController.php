<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /notifications, POST /notifications/{id}/read, POST /notifications/read-all
 * (PROJECT_CONTEXT.md Sprint 8 "Notifications").
 *
 * All three run behind `auth:sanctum` + `tenant` (see routes/api.php) — no extra
 * Permissions::* gate: per PROJECT_CONTEXT.md, "a user only ever sees their own [notifications]",
 * so the only authorization that matters is "is this notification mine", checked per-row below
 * rather than via a Gate ability.
 *
 * Notification uses BelongsToOrganization (see model docblock), so every query built through
 * `Notification::query()` is already scoped to the current tenant by the OrganizationScope
 * global scope — the explicit `where('user_id', ...)` below is what narrows further to "this
 * user's own", matching PROJECT_CONTEXT.md's exact query shape
 * (`where('user_id', $request->user()->id)`).
 */
class NotificationController extends Controller
{
    /**
     * Paginated, newest first. Mirrors ProjectController::index()'s
     * `paginate($request->integer('per_page', 20))` pagination convention.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return NotificationResource::collection($notifications)->response();
    }

    /**
     * Marks one notification read. 404 (not 403) if the notification doesn't exist OR belongs
     * to another user — per PROJECT_CONTEXT.md's explicit "don't leak existence of other users'
     * notifications" instruction, both cases must be indistinguishable to the caller.
     *
     * Idempotent: calling this again on an already-read notification is a no-op success (200
     * with the unchanged resource), not an error — PROJECT_CONTEXT.md is explicit that a second
     * call must not fail.
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $model = Notification::query()
            ->where('user_id', $request->user()->id)
            ->find($notification);

        if (! $model) {
            return $this->notFound();
        }

        if ($model->read_at === null) {
            $model->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'data' => new NotificationResource($model),
        ]);
    }

    /**
     * Marks every one of the current user's UNREAD notifications read in one query (not a
     * per-row loop) — there's nothing per-row to compute (read_at is always `now()`), so a bulk
     * UPDATE is both simpler and avoids N round-trips. Uses the `unread()` scope
     * (App\Models\Notification::scopeUnread) so this stays in sync with any future change to
     * what "unread" means.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => ['marked_read' => $count],
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
