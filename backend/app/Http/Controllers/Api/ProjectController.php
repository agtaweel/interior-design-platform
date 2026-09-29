<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProjectsRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Snag;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * All endpoints run behind `auth:sanctum` + `tenant`; reads (index/show) need no extra
 * permission beyond an active membership, writes are gated inside the Form Requests via
 * Permissions::MANAGE_PROJECTS.
 */
class ProjectController extends Controller
{
    private const RELATIONS = ['client', 'property', 'responsibleUser', 'projectMembers.user', 'services'];

    public function index(IndexProjectsRequest $request): JsonResponse
    {
        $query = Project::query()->with(['client', 'property', 'responsibleUser']);

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($clientId = $request->validated('client_id')) {
            $query->where('client_id', $clientId);
        }

        if ($search = $request->validated('q')) {
            $needle = mb_strtolower($search);

            // See ClientController::index() for why LOWER()+LIKE is used instead of `ilike`.
            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$needle}%"]);
            });
        }

        $projects = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return ProjectResource::collection($projects)->response();
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $project = Project::create([
                ...$data,
                'code' => $data['code'] ?? $this->generateProjectCode(),
                'status' => $data['status'] ?? 'draft',
                // Never trust a client-supplied organization_id — always the resolved tenant.
                'organization_id' => app(TenantContext::class)->organizationId(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $this->error(409, 'project_code_conflict', 'That project code is already in use.');
            }

            throw $e;
        }

        return response()->json([
            'data' => new ProjectResource($project->fresh(self::RELATIONS)),
        ], 201);
    }

    public function show(string $project): JsonResponse
    {
        $model = Project::query()->with(self::RELATIONS)->find($project);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json([
            'data' => new ProjectResource($model),
        ]);
    }

    public function update(UpdateProjectRequest $request, string $project): JsonResponse
    {
        $model = Project::find($project);

        if (! $model) {
            return $this->notFound();
        }

        $data = $request->validated();

        // BRD's explicit rule: "Project cannot be marked complete with unresolved mandatory
        // snags" — same check HandoverController::store() applies, since a handover is the
        // other path to a project reaching 'completed'. See Snag::hasOpenMandatorySnags()'s
        // docblock for why this lives on the model rather than being duplicated here.
        if (($data['status'] ?? null) === 'completed' && Snag::hasOpenMandatorySnags($model->id)) {
            return $this->error(
                409,
                'unresolved_mandatory_snags',
                'This project has unresolved mandatory snags and cannot be marked completed yet.',
            );
        }

        $model->update($data);

        return response()->json([
            'data' => new ProjectResource($model->fresh(self::RELATIONS)),
        ]);
    }

    /**
     * Best-effort sequential project code (e.g. "PRJ-00007") for callers that don't supply
     * their own — scoped to the current organization via the already-scoped `Project::query()`
     * count. Under concurrent creation this can theoretically collide (Sprint 1 scale doesn't
     * warrant a DB sequence/lock for this); a collision surfaces as a 409 via the unique
     * constraint catch in store() rather than silently overwriting another project, and
     * callers can always avoid it entirely by supplying an explicit `code`.
     */
    private function generateProjectCode(): string
    {
        $next = Project::query()->count() + 1;

        return 'PRJ-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 = Postgres unique_violation; sqlite (used by the test suite) reports it via
        // the driver message instead of a portable SQLSTATE code.
        return $e->getCode() === '23505' || str_contains(strtolower($e->getMessage()), 'unique constraint');
    }

    private function notFound(): JsonResponse
    {
        return $this->error(404, 'not_found', 'The requested resource was not found.');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) [],
            ],
        ], $status);
    }
}
