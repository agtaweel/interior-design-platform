<?php

namespace Tests\Feature\Marketplace;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlisted_organizations_never_appear_in_browse_results(): void
    {
        Organization::factory()->create(['name' => 'Hidden Studio']);

        $this->getJson('/api/v1/public/marketplace/organizations')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_listed_organizations_appear_in_browse_results(): void
    {
        $organization = Organization::factory()->create(['name' => 'Nile & Co. Interiors']);
        OrganizationProfile::factory()->listed()->create(['organization_id' => $organization->id]);

        $response = $this->getJson('/api/v1/public/marketplace/organizations');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nile & Co. Interiors');
    }

    public function test_show_returns_404_for_an_unlisted_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson("/api/v1/public/marketplace/organizations/{$organization->id}")
            ->assertStatus(404);
    }

    public function test_show_returns_full_profile_for_a_listed_organization(): void
    {
        $organization = Organization::factory()->create();
        OrganizationProfile::factory()->listed()->create([
            'organization_id' => $organization->id,
            'description' => 'We build beautiful homes.',
        ]);

        $this->getJson("/api/v1/public/marketplace/organizations/{$organization->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.description', 'We build beautiful homes.');
    }

    public function test_setting_listed_true_fails_without_a_description(): void
    {
        [$user, $organization] = $this->makeOwner();

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->withHeader('X-Organization-Id', (string) $organization->id)
            ->patchJson("/api/v1/organizations/{$organization->id}/profile", [
                'is_marketplace_listed' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'marketplace_listing_requires_description');
    }

    public function test_setting_listed_true_succeeds_once_a_description_exists(): void
    {
        [$user, $organization] = $this->makeOwner();

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->withHeader('X-Organization-Id', (string) $organization->id)
            ->patchJson("/api/v1/organizations/{$organization->id}/profile", [
                'description' => 'Full-service studio.',
            ])->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t2')->plainTextToken)
            ->withHeader('X-Organization-Id', (string) $organization->id)
            ->patchJson("/api/v1/organizations/{$organization->id}/profile", [
                'is_marketplace_listed' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.is_marketplace_listed', true);
    }

    public function test_setting_listed_true_succeeds_in_the_same_request_as_the_first_description(): void
    {
        // Regression: updateProfile() creates the OrganizationProfile row, then setListed() must
        // see that freshly-created row rather than a cached-null relation from before it existed.
        [$user, $organization] = $this->makeOwner();

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->withHeader('X-Organization-Id', (string) $organization->id)
            ->patchJson("/api/v1/organizations/{$organization->id}/profile", [
                'description' => 'Full-service studio.',
                'is_marketplace_listed' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.is_marketplace_listed', true)
            ->assertJsonPath('data.description', 'Full-service studio.');
    }

    public function test_a_member_without_manage_organization_cannot_update_the_profile(): void
    {
        $organization = Organization::factory()->create();
        $role = Role::factory()->create([
            'organization_id' => null,
            'name' => 'Designer',
            'permissions_json' => array_fill_keys(Permissions::ALL, false),
        ]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->withHeader('X-Organization-Id', (string) $organization->id)
            ->patchJson("/api/v1/organizations/{$organization->id}/profile", [
                'description' => 'Trying anyway.',
            ])
            ->assertStatus(403);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function makeOwner(): array
    {
        $organization = Organization::factory()->create();
        $role = Role::factory()->create([
            'organization_id' => null,
            'name' => 'Owner',
            'permissions_json' => array_fill_keys(Permissions::ALL, true),
        ]);
        $user = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return [$user, $organization];
    }
}
