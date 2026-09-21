<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_issues_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'designer@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'designer@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_with_wrong_password_returns_401_envelope(): void
    {
        User::factory()->create([
            'email' => 'designer@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'designer@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_login_with_unknown_email_returns_401_without_confirming_account_existence(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_me_returns_user_and_organization_memberships(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create(['name' => 'Acme Interiors']);
        $role = Role::factory()->create(['organization_id' => null, 'name' => 'Owner']);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.organizations.0.organization.name', 'Acme Interiors')
            ->assertJsonPath('data.organizations.0.role.name', 'Owner')
            ->assertJsonPath('data.organizations.0.status', 'active');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $tokenB = $user->createToken('device-b')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'device-a',
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'device-b',
        ]);

        // Laravel's `sanctum` RequestGuard caches its resolved user for the lifetime of the
        // guard instance, which (unlike in real usage, where every request boots a fresh
        // application) persists across these sequential in-test HTTP calls. Forgetting the
        // guard forces it to re-resolve the user from each request's bearer token, same as a
        // real second request would.
        $this->app['auth']->forgetGuards();

        // The other device's token still works.
        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson('/api/v1/me')
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();

        // The revoked token no longer works.
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }
}
