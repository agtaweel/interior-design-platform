<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * All endpoints here run behind the `auth:sanctum` + `tenant` middleware (see routes/api.php),
 * with {organization} as the route-bound tenant context — so by the time these methods run,
 * ResolveTenantContext has already confirmed the requester has an ACTIVE membership in
 * $organization. invite()/update() additionally check that it's an admin-level membership, via
 * the manage_members permission gate.
 *
 * index() (PROJECT_CONTEXT.md Sprint 8 "Settings" — S22 members list) is deliberately the one
 * endpoint here that does NOT add an extra Gate::authorize() call: per that section, "active
 * membership-only read is fine here (not financial data)" — same "reads need only active
 * membership" posture as every other list endpoint in this codebase (ClientController::index,
 * ProjectController::index, etc), matching the fact that member name/email/role/status carries
 * no cost/margin information the stricter gates (MANAGE_BOQ/VIEW_FINANCIALS/MANAGE_ORGANIZATION)
 * exist to protect.
 */
class OrganizationMemberController extends Controller
{
    /**
     * GET /organizations/{organization}/members — list every membership row (any status:
     * active/invited/suspended, per memberPayload()'s existing shape) for the current tenant,
     * not just active ones; "status" is part of what this listing exists to show (e.g. an admin
     * needs to see pending invitations too), so it is a column in the payload, not a filter.
     */
    public function index(Organization $organization): JsonResponse
    {
        $members = OrganizationMember::query()
            ->where('organization_id', $organization->id)
            ->with(['user', 'role'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $members->map(fn (OrganizationMember $member) => $this->memberPayload($member))->all(),
        ]);
    }

    public function invite(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_MEMBERS);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'role_id' => ['required', 'integer'],
        ]);

        // Role::find respects OrganizationScope (tenant context is $organization here), so this
        // only ever resolves a role that is either global (organization_id null) or already
        // scoped to $organization — a role_id belonging to a different organization simply
        // won't be found.
        $role = Role::find($data['role_id']);

        if (! $role) {
            return $this->error(422, 'invalid_role', 'That role does not exist for this organization.');
        }

        return DB::transaction(function () use ($data, $organization, $role) {
            $user = User::where('email', $data['email'])->first();

            if (! $user) {
                $user = User::create([
                    'name' => $data['name'] ?? Str::before($data['email'], '@'),
                    'email' => $data['email'],
                    // Random, unusable password: the invited user sets a real one via an
                    // accept-invitation flow (out of Sprint 1 scope — organizations/roles/
                    // auth foundation only). They cannot log in until they do.
                    'password' => Hash::make(Str::random(40)),
                    'auth_provider' => 'local',
                    'status' => 'invited',
                ]);
            }

            $existing = OrganizationMember::where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                return $this->error(409, 'member_already_exists', 'This user already has a membership in this organization.');
            }

            $member = OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'status' => 'invited',
            ]);

            return response()->json([
                'data' => $this->memberPayload($member->fresh(['user', 'role'])),
            ], 201);
        });
    }

    public function update(Request $request, Organization $organization, int $member): JsonResponse
    {
        Gate::authorize(Permissions::MANAGE_MEMBERS);

        $data = $request->validate([
            'role_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(['active', 'invited', 'suspended'])],
        ]);

        // Explicit organization_id filter here (not just relying on the model's global scope)
        // since this is the exact operation the isolation test targets: an admin of one
        // organization must never be able to reach another organization's membership row by
        // guessing its id.
        $membership = OrganizationMember::where('organization_id', $organization->id)
            ->where('id', $member)
            ->first();

        if (! $membership) {
            return $this->error(404, 'not_found', 'The requested resource was not found.');
        }

        if (isset($data['role_id'])) {
            $role = Role::find($data['role_id']);

            if (! $role) {
                return $this->error(422, 'invalid_role', 'That role does not exist for this organization.');
            }

            $membership->role_id = $role->id;
        }

        if (isset($data['status'])) {
            $membership->status = $data['status'];
        }

        $membership->save();

        return response()->json([
            'data' => $this->memberPayload($membership->fresh(['user', 'role'])),
        ]);
    }

    private function memberPayload(OrganizationMember $member): array
    {
        return [
            'id' => $member->id,
            'user' => [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
            ],
            'role' => $member->role ? [
                'id' => $member->role->id,
                'name' => $member->role->name,
            ] : null,
            'status' => $member->status,
        ];
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
