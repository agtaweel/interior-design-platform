<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\TenantMismatchException;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * THE critical test for this codebase: a user authenticated into organization A must never be
 * able to read or write organization B's data, even by guessing/enumerating IDs directly.
 *
 * Sprint 1's actual Client/Property/Project HTTP endpoints are backend-api-engineer's scope
 * (not yet built as of this test). What auth-security-engineer owns and must prove here is the
 * underlying mechanism those future controllers will run on top of: the `tenant` middleware
 * (App\Http\Middleware\ResolveTenantContext) plus the OrganizationScope global scope wired via
 * App\Models\Concerns\BelongsToOrganization. So this test registers minimal stand-in routes
 * that do exactly what a real controller would (plain Eloquent CRUD against the real tenant
 * models), running through the real auth+tenant middleware stack — not a shortcut, not a unit
 * test of the scope class in isolation.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'tenant'])->group(function () {
            Route::get('/_test/clients', fn () => response()->json([
                'data' => Client::query()->pluck('id'),
            ]));

            Route::get('/_test/clients/{id}', fn (string $id) => $this->showOrNotFound(Client::find($id)));
            Route::get('/_test/properties/{id}', fn (string $id) => $this->showOrNotFound(Property::find($id)));
            Route::get('/_test/projects/{id}', fn (string $id) => $this->showOrNotFound(Project::find($id)));

            Route::patch('/_test/clients/{id}', function (Request $request, string $id) {
                $affected = Client::where('id', $id)->update($request->only('name'));

                return response()->json(['data' => ['affected' => $affected]]);
            });

            Route::delete('/_test/clients/{id}', function (string $id) {
                $affected = Client::where('id', $id)->delete();

                return response()->json(['data' => ['affected' => $affected]]);
            });

            Route::post('/_test/clients', function (Request $request) {
                $client = Client::create($request->only('organization_id', 'name', 'phone', 'email'));

                return response()->json(['data' => ['id' => $client->id]], 201);
            });
        });
    }

    private function showOrNotFound(?\Illuminate\Database\Eloquent\Model $model)
    {
        return $model
            ? response()->json(['data' => ['id' => $model->id]])
            : response()->json(['error' => ['code' => 'not_found']], 404);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function createActiveOrgAndUser(?array $permissions = null): array
    {
        $organization = Organization::factory()->create();
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => $permissions ?? []]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return [$organization, $user];
    }

    private function authHeaderFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_user_only_lists_their_own_organizations_clients(): void
    {
        [$orgA, $userA] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();

        $clientA = Client::factory()->create(['organization_id' => $orgA->id]);
        Client::factory()->create(['organization_id' => $orgB->id]);

        $response = $this->withHeaders($this->authHeaderFor($userA))->getJson('/_test/clients');

        $response->assertStatus(200)->assertJson(['data' => [$clientA->id]]);
    }

    public function test_user_cannot_read_another_organizations_client_by_guessing_its_id(): void
    {
        [, $userA] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);

        $this->withHeaders($this->authHeaderFor($userA))
            ->getJson("/_test/clients/{$clientB->id}")
            ->assertStatus(404);
    }

    public function test_user_cannot_read_another_organizations_property_or_project_by_guessing_id(): void
    {
        [, $userA] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);
        $propertyB = Property::factory()->create(['client_id' => $clientB->id, 'organization_id' => $orgB->id]);
        $projectB = Project::factory()->create(['client_id' => $clientB->id, 'organization_id' => $orgB->id]);

        $headers = $this->authHeaderFor($userA);

        $this->withHeaders($headers)->getJson("/_test/properties/{$propertyB->id}")->assertStatus(404);
        $this->withHeaders($headers)->getJson("/_test/projects/{$projectB->id}")->assertStatus(404);
    }

    public function test_user_cannot_update_another_organizations_client_by_guessing_its_id(): void
    {
        [, $userA] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();
        $clientB = Client::factory()->create(['organization_id' => $orgB->id, 'name' => 'Original Name']);

        $response = $this->withHeaders($this->authHeaderFor($userA))
            ->patchJson("/_test/clients/{$clientB->id}", ['name' => 'Hacked Name']);

        $response->assertStatus(200)->assertJsonPath('data.affected', 0);
        $this->assertSame('Original Name', $clientB->fresh()->name);
    }

    public function test_user_cannot_delete_another_organizations_client_by_guessing_its_id(): void
    {
        [, $userA] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);

        $response = $this->withHeaders($this->authHeaderFor($userA))
            ->deleteJson("/_test/clients/{$clientB->id}");

        $response->assertStatus(200)->assertJsonPath('data.affected', 0);
        $this->assertDatabaseHas('clients', ['id' => $clientB->id]);
    }

    public function test_user_cannot_create_a_client_for_another_organization_by_spoofing_organization_id(): void
    {
        [$orgA, $userA] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();

        $response = $this->withHeaders($this->authHeaderFor($userA))->postJson('/_test/clients', [
            'organization_id' => $orgB->id,
            'name' => 'Spoofed Client',
        ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'tenant_mismatch');
        $this->assertDatabaseMissing('clients', ['name' => 'Spoofed Client']);
    }

    public function test_user_cannot_switch_into_an_organization_they_are_not_a_member_of_via_header(): void
    {
        [, $userA] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();
        Client::factory()->create(['organization_id' => $orgB->id]);

        $headers = array_merge($this->authHeaderFor($userA), ['X-Organization-Id' => (string) $orgB->id]);

        $this->withHeaders($headers)
            ->getJson('/_test/clients')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'no_active_membership');
    }

    public function test_member_belonging_to_both_organizations_can_switch_between_them_via_header(): void
    {
        [$orgA, $user] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();
        $role = Role::factory()->create(['organization_id' => null]);
        OrganizationMember::factory()->create([
            'organization_id' => $orgB->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $clientA = Client::factory()->create(['organization_id' => $orgA->id]);
        $clientB = Client::factory()->create(['organization_id' => $orgB->id]);

        $this->withHeaders(array_merge($this->authHeaderFor($user), ['X-Organization-Id' => (string) $orgA->id]))
            ->getJson('/_test/clients')
            ->assertJson(['data' => [$clientA->id]]);

        $this->withHeaders(array_merge($this->authHeaderFor($user), ['X-Organization-Id' => (string) $orgB->id]))
            ->getJson('/_test/clients')
            ->assertJson(['data' => [$clientB->id]]);
    }

    public function test_multi_org_member_without_header_gets_a_context_required_error_instead_of_a_silent_default(): void
    {
        [$orgA, $user] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();
        $role = Role::factory()->create(['organization_id' => null]);
        OrganizationMember::factory()->create([
            'organization_id' => $orgB->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->withHeaders($this->authHeaderFor($user))
            ->getJson('/_test/clients')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'organization_context_required');
    }

    /**
     * Belt-and-suspenders unit-level proof that the write guard lives on the model itself
     * (BelongsToOrganization), not just in the test route: even a direct Eloquent call made
     * while a tenant context is active throws, regardless of how the code reached that call.
     */
    public function test_belongs_to_organization_guard_throws_on_direct_model_use_with_mismatched_org(): void
    {
        [$orgA] = $this->createActiveOrgAndUser();
        [$orgB] = $this->createActiveOrgAndUser();

        $context = app(\App\Support\Tenancy\TenantContext::class);
        $context->set($orgA->id);

        $this->expectException(TenantMismatchException::class);

        try {
            Client::create(['organization_id' => $orgB->id, 'name' => 'Direct Attempt']);
        } finally {
            $context->clear();
        }
    }
}
