<?php

namespace Tests\Feature\Marketplace;

use App\Models\ClientUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * BRD v4 "Client Marketplace" — mirrors AuthTest's structure for the parallel ClientUser/
 * ClientAuthController stack.
 */
class ClientAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_client_and_issues_a_token(): void
    {
        $response = $this->postJson('/api/v1/client/auth/register', [
            'name' => 'Laila Farouk',
            'email' => 'laila@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.client.email', 'laila@example.com')
            ->assertJsonStructure(['data' => ['token', 'client' => ['id', 'name', 'email']]]);

        $this->assertDatabaseHas('client_users', ['email' => 'laila@example.com']);
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        ClientUser::factory()->create(['email' => 'laila@example.com']);

        $this->postJson('/api/v1/client/auth/register', [
            'name' => 'Laila Farouk',
            'email' => 'laila@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422);
    }

    public function test_login_with_valid_credentials_issues_a_token(): void
    {
        $client = ClientUser::factory()->create([
            'email' => 'laila@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/client/auth/login', [
            'email' => 'laila@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.client.id', $client->id);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        ClientUser::factory()->create([
            'email' => 'laila@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/v1/client/auth/login', [
            'email' => 'laila@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_me_requires_a_client_account(): void
    {
        $this->getJson('/api/v1/client/me')
            ->assertStatus(401);
    }

    public function test_a_staff_user_cannot_use_client_only_routes(): void
    {
        $staffUser = \App\Models\User::factory()->create();
        $token = $staffUser->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'client_account_required');
    }

    public function test_a_client_user_cannot_use_staff_tenant_routes(): void
    {
        $clientUser = ClientUser::factory()->create();
        $token = $clientUser->createToken('client-api')->plainTextToken;
        $organization = \App\Models\Organization::factory()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Organization-Id', (string) $organization->id)
            ->getJson('/api/v1/clients')
            ->assertStatus(403);
    }

    public function test_me_returns_the_authenticated_client(): void
    {
        $client = ClientUser::factory()->create();
        $token = $client->createToken('client-api')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/me')
            ->assertStatus(200)
            ->assertJsonPath('data.client.id', $client->id);
    }
}
