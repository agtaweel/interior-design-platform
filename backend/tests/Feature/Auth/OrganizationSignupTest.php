<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Platform Readiness Review finding #01 (OrganizationSignupService, AuthController::register()).
 */
class OrganizationSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_signup_creates_an_organization_a_user_and_logs_them_in_as_owner(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Acme Interiors',
            'name' => 'Jordan Lee',
            'email' => 'jordan@acme.test',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ]);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame('jordan@acme.test', $response->json('data.user.email'));

        $organization = Organization::where('name', 'Acme Interiors')->firstOrFail();
        $user = User::where('email', 'jordan@acme.test')->firstOrFail();

        $this->assertTrue(Hash::check('super-secret-1', $user->password));
        $this->assertSame('active', $user->status);

        $membership = OrganizationMember::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame('active', $membership->status);
        $this->assertSame('Owner', $membership->role->name);

        // The issued token is immediately usable — no separate "verify your email" step blocks
        // access, matching PROJECT_CONTEXT.md's "self-serve, no approval queue" framing.
        $token = $response->json('data.token');
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('data.organizations.0.organization.name', 'Acme Interiors')
            ->assertJsonPath('data.organizations.0.role.name', 'Owner');
    }

    public function test_signup_defaults_the_new_organizations_currency_to_egp(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Acme Interiors',
            'name' => 'Jordan Lee',
            'email' => 'jordan@acme.test',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ])->assertStatus(201);

        $this->assertSame('EGP', Organization::where('name', 'Acme Interiors')->firstOrFail()->currency);
    }

    public function test_signup_rejects_an_email_that_already_has_an_account(): void
    {
        User::factory()->create(['email' => 'jordan@acme.test']);

        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Acme Interiors',
            'name' => 'Jordan Lee',
            'email' => 'jordan@acme.test',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.email.0', 'The email has already been taken.');
    }

    public function test_signup_requires_an_organization_name(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jordan Lee',
            'email' => 'jordan@acme.test',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['organization_name']]]);
    }

    public function test_signup_requires_matching_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Acme Interiors',
            'name' => 'Jordan Lee',
            'email' => 'jordan@acme.test',
            'password' => 'super-secret-1',
            'password_confirmation' => 'does-not-match',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['password']]]);
    }

    public function test_signup_requires_a_password_of_at_least_eight_characters(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Acme Interiors',
            'name' => 'Jordan Lee',
            'email' => 'jordan@acme.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['password']]]);
    }

    public function test_two_different_signups_do_not_see_each_others_organization(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Acme Interiors',
            'name' => 'Jordan Lee',
            'email' => 'jordan@acme.test',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ])->assertStatus(201);

        $second = $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Beacon Design Studio',
            'name' => 'Sam Rivera',
            'email' => 'sam@beacon.test',
            'password' => 'super-secret-2',
            'password_confirmation' => 'super-secret-2',
        ]);
        $second->assertStatus(201);

        $token = $second->json('data.token');
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.organizations')
            ->assertJsonPath('data.organizations.0.organization.name', 'Beacon Design Studio');
    }
}
