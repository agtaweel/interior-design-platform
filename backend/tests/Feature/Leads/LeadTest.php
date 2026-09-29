<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * BRD "CRM/Leads": pipeline, source, budget, notes, conversion to client/project. Mirrors the
 * memberWithPermissions/authHeader helper pattern established across the Payments/Projects test
 * suites.
 */
class LeadTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPermissions(Organization $organization, array $permissions): User
    {
        $role = Role::factory()->create(['organization_id' => null, 'permissions_json' => $permissions]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** @return array{0: Organization, 1: User} */
    private function setUp2(array $permissions = [Permissions::MANAGE_LEADS => true]): array
    {
        $organization = Organization::factory()->create();
        $user = $this->memberWithPermissions($organization, $permissions);

        return [$organization, $user];
    }

    public function test_store_creates_a_lead_scoped_to_the_current_organization(): void
    {
        [$organization, $user] = $this->setUp2();

        $response = $this->withHeaders($this->authHeader($user))->postJson('/api/v1/leads', [
            'name' => 'Mona Abdelrahman',
            'phone' => '01012345678',
            'source' => 'referral',
            'estimated_budget' => 250000,
        ]);

        $response->assertStatus(201);
        $this->assertSame('new', $response->json('data.status'));
        $lead = Lead::first();
        $this->assertSame($organization->id, $lead->organization_id);
    }

    public function test_index_lists_only_the_current_organizations_leads(): void
    {
        [$organization, $user] = $this->setUp2();
        $otherOrg = Organization::factory()->create();
        Lead::factory()->create(['organization_id' => $otherOrg->id]);
        Lead::factory()->count(2)->create(['organization_id' => $organization->id]);

        $response = $this->withHeaders($this->authHeader($user))->getJson('/api/v1/leads');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_update_changes_status_through_the_pipeline(): void
    {
        [$organization, $user] = $this->setUp2();
        $lead = Lead::factory()->create(['organization_id' => $organization->id, 'status' => 'new']);

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/leads/{$lead->id}", ['status' => 'contacted']);

        $response->assertStatus(200);
        $this->assertSame('contacted', $response->json('data.status'));
    }

    public function test_update_rejects_setting_status_to_converted_directly(): void
    {
        [$organization, $user] = $this->setUp2();
        $lead = Lead::factory()->create(['organization_id' => $organization->id, 'status' => 'qualified']);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/leads/{$lead->id}", ['_method' => 'PATCH', 'status' => 'converted']);

        $response->assertStatus(422);
    }

    public function test_convert_creates_a_client_and_stamps_the_lead(): void
    {
        [$organization, $user] = $this->setUp2();
        $lead = Lead::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'qualified',
            'name' => 'Karim El Sayed',
            'phone' => '01123456789',
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/leads/{$lead->id}/convert", []);

        $response->assertStatus(201);
        $this->assertSame('converted', $response->json('data.lead.status'));
        $this->assertSame('Karim El Sayed', $response->json('data.client.name'));
        $this->assertNull($response->json('data.project'));

        $lead->refresh();
        $this->assertNotNull($lead->converted_client_id);
        $this->assertNotNull($lead->converted_at);
        $this->assertNull($lead->converted_project_id);
    }

    public function test_convert_with_create_project_creates_both_client_and_project(): void
    {
        [$organization, $user] = $this->setUp2();
        $lead = Lead::factory()->create(['organization_id' => $organization->id, 'status' => 'qualified']);

        $response = $this->withHeaders($this->authHeader($user))->postJson(
            "/api/v1/leads/{$lead->id}/convert",
            ['create_project' => true, 'project_name' => 'Zamalek Apartment Finishing'],
        );

        $response->assertStatus(201);
        $this->assertSame('Zamalek Apartment Finishing', $response->json('data.project.name'));
        $this->assertSame($response->json('data.client.id'), $response->json('data.project.client.id'));

        $lead->refresh();
        $this->assertNotNull($lead->converted_project_id);
    }

    public function test_convert_requires_project_name_when_create_project_is_true(): void
    {
        [$organization, $user] = $this->setUp2();
        $lead = Lead::factory()->create(['organization_id' => $organization->id, 'status' => 'new']);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/leads/{$lead->id}/convert", ['create_project' => true]);

        $response->assertStatus(422);
    }

    public function test_convert_twice_is_rejected_with_409(): void
    {
        [$organization, $user] = $this->setUp2();
        $lead = Lead::factory()->create(['organization_id' => $organization->id, 'status' => 'qualified']);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/leads/{$lead->id}/convert", [])
            ->assertStatus(201);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/leads/{$lead->id}/convert", [])
            ->assertStatus(409);
    }

    public function test_mutations_without_manage_leads_permission_are_rejected_with_403(): void
    {
        [$organization, $user] = $this->setUp2([Permissions::MANAGE_LEADS => false]);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/leads', ['name' => 'Nour Hassan'])
            ->assertStatus(403);

        $lead = Lead::factory()->create(['organization_id' => $organization->id]);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/v1/leads/{$lead->id}", ['status' => 'contacted'])
            ->assertStatus(403);

        $this->withHeaders($this->authHeader($user))
            ->postJson("/api/v1/leads/{$lead->id}/convert", [])
            ->assertStatus(403);
    }

    public function test_a_member_of_another_organization_cannot_see_or_convert_this_lead(): void
    {
        [$organization] = $this->setUp2();
        $lead = Lead::factory()->create(['organization_id' => $organization->id]);

        $otherOrg = Organization::factory()->create();
        $outsider = $this->memberWithPermissions($otherOrg, [Permissions::MANAGE_LEADS => true]);

        Auth::forgetGuards();

        $this->withHeaders($this->authHeader($outsider))
            ->getJson("/api/v1/leads/{$lead->id}")
            ->assertStatus(404);

        $this->withHeaders($this->authHeader($outsider))
            ->postJson("/api/v1/leads/{$lead->id}/convert", [])
            ->assertStatus(404);
    }
}
