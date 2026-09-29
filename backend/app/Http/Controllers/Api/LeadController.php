<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertLeadRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\LeadResource;
use App\Http\Resources\ProjectResource;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET/POST /leads, GET/PATCH /leads/{lead}, POST /leads/{lead}/convert (BRD "CRM/Leads": lead
 * pipeline, source, budget, notes, conversion to client/project).
 *
 * Reads (index/show) require only an active membership, matching this codebase's default read
 * posture (ClientController::index() etc); mutations (store/update/convert) are gated behind
 * Permissions::MANAGE_LEADS inside each FormRequest.
 *
 * {lead} follows the same manual-lookup convention as every other cross-cutting controller in
 * this codebase (see routes/api.php's top docblock) — Lead::find() is already org-scoped via
 * BelongsToOrganization's global scope.
 */
class LeadController extends Controller
{
    public function index(): JsonResponse
    {
        $leads = Lead::query()
            ->with('owner')
            ->orderByDesc('created_at')
            ->paginate(request()->integer('per_page', 50));

        return LeadResource::collection($leads)->response();
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $lead = Lead::create([
            ...$request->validated(),
            'organization_id' => app(TenantContext::class)->organizationId(),
            'status' => $request->validated('status', 'new'),
        ]);

        return response()->json([
            'data' => new LeadResource($lead->fresh('owner')),
        ], 201);
    }

    public function show(string $lead): JsonResponse
    {
        $model = Lead::query()->with('owner')->find($lead);

        if (! $model) {
            return $this->notFound();
        }

        return response()->json(['data' => new LeadResource($model)]);
    }

    public function update(UpdateLeadRequest $request, string $lead): JsonResponse
    {
        $model = Lead::find($lead);

        if (! $model) {
            return $this->notFound();
        }

        if ($model->status === 'converted') {
            return $this->error(409, 'lead_already_converted', 'A converted lead cannot be edited further.');
        }

        $model->update($request->validated());

        return response()->json(['data' => new LeadResource($model->fresh('owner'))]);
    }

    /**
     * Creates a Client from the lead (always), and — only when `create_project` is true — a
     * Project for that client too, using the same auto-generated sequential code convention as
     * ProjectController::generateProjectCode(). Wrapped in a transaction: per PROJECT_CONTEXT.md's
     * "financial/state transitions execute inside transactions" rule (this mirrors that for a
     * commercial-lifecycle transition even though no money moves here yet), a lead must never end
     * up half-converted (client created but the lead's own converted_* stamp missing, or vice
     * versa).
     */
    public function convert(ConvertLeadRequest $request, string $lead): JsonResponse
    {
        $model = Lead::find($lead);

        if (! $model) {
            return $this->notFound();
        }

        if ($model->status === 'converted') {
            return $this->error(409, 'lead_already_converted', 'This lead has already been converted.');
        }

        $organizationId = app(TenantContext::class)->organizationId();
        $data = $request->validated();

        $result = DB::transaction(function () use ($model, $organizationId, $data) {
            $client = Client::create([
                'organization_id' => $organizationId,
                'name' => $model->name,
                'phone' => $model->phone,
                'email' => $model->email,
                'notes' => $model->notes,
            ]);

            $project = null;

            if ($data['create_project'] ?? false) {
                $nextSeq = Project::query()->count() + 1;
                $project = Project::create([
                    'organization_id' => $organizationId,
                    'client_id' => $client->id,
                    'code' => 'PRJ-'.str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT),
                    'name' => $data['project_name'],
                    'status' => 'draft',
                ]);
            }

            // forceFill(), not update(): converted_client_id/converted_project_id/converted_at
            // are deliberately excluded from Lead's #[Fillable] (see that model's docblock) so
            // a client request body can never set them — mass-assigning them here via update()
            // would therefore be silently dropped by Eloquent's fillable guard.
            $model->forceFill([
                'status' => 'converted',
                'converted_client_id' => $client->id,
                'converted_project_id' => $project?->id,
                'converted_at' => now(),
            ])->save();

            return [$client, $project];
        });

        [$client, $project] = $result;

        return response()->json([
            'data' => [
                'lead' => new LeadResource($model->fresh('owner')),
                'client' => new ClientResource($client),
                // ProjectResource's `client` field is `new ClientSummaryResource($this->whenLoaded('client'))`
                // — the relation must be eager-loaded before wrapping, same as
                // ProjectController::store()'s `$project->fresh(RELATIONS)` call.
                'project' => $project ? new ProjectResource($project->fresh('client')) : null,
            ],
        ], 201);
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
