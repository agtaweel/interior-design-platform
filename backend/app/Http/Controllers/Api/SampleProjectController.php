<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Projects\SampleProjectService;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET/POST/DELETE /sample-project — the current company's one-click sample project
 * (docs/SIMPLIFICATION_REVIEW.md P4.3, see SampleProjectService). Reading is open to any member;
 * creating and removing it need manage_projects, the same tier as creating a real project.
 */
class SampleProjectController extends Controller
{
    public function __construct(private readonly SampleProjectService $samples) {}

    public function show(): JsonResponse
    {
        $project = $this->samples->current($this->organization());

        return response()->json(['data' => $project ? ['project_id' => $project->id] : null]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROJECTS);

        $locale = $request->input('locale') === 'ar' ? 'ar' : 'en';
        $project = $this->samples->create($this->organization(), $request->user(), $locale);

        return response()->json(['data' => ['project_id' => $project->id]], 201);
    }

    public function destroy(): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_PROJECTS);

        $this->samples->delete($this->organization());

        return response()->json(status: 204);
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail(app(TenantContext::class)->organizationId());
    }
}
