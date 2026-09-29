<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Platform Readiness Review finding #04 (PasswordResetService, AuthController::
 * forgotPassword()/resetPassword()).
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_reset_email_for_a_known_address(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'staff@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'staff@example.com'])
            ->assertStatus(200);

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo('staff@example.com'));
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_forgot_password_returns_the_same_generic_response_for_an_unknown_address(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $response->assertStatus(200);
        $this->assertSame(
            'If an account exists for that email, a reset link has been sent.',
            $response->json('data.message'),
        );
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_password_with_a_valid_token_changes_the_password_and_revokes_existing_tokens(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'staff@example.com', 'password' => 'old-password-123']);
        $existingToken = $user->createToken('api')->plainTextToken;

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'staff@example.com'])->assertStatus(200);

        $sentUrl = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$sentUrl) {
            $sentUrl = $mail->resetUrl;

            return true;
        });
        parse_str((string) parse_url($sentUrl, PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'staff@example.com',
            'token' => $query['token'],
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);

        // The old Sanctum token no longer works.
        $this->withHeaders(['Authorization' => "Bearer {$existingToken}"])
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_reset_password_with_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.com']);
        DB::table('password_reset_tokens')->insert([
            'email' => 'staff@example.com',
            'token' => Hash::make('the-real-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'staff@example.com',
            'token' => 'a-completely-wrong-token',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'password_reset_token_invalid');
    }

    public function test_reset_password_with_an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.com']);
        DB::table('password_reset_tokens')->insert([
            'email' => 'staff@example.com',
            'token' => Hash::make('the-real-token'),
            'created_at' => now()->subMinutes(120),
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'staff@example.com',
            'token' => 'the-real-token',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'password_reset_token_invalid');
    }

    public function test_a_second_forgot_password_request_invalidates_the_first_tokens_link(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'staff@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'staff@example.com'])->assertStatus(200);
        $firstUrl = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$firstUrl) {
            $firstUrl = $mail->resetUrl;

            return true;
        });
        parse_str((string) parse_url($firstUrl, PHP_URL_QUERY), $firstQuery);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'staff@example.com'])->assertStatus(200);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'staff@example.com',
            'token' => $firstQuery['token'],
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'password_reset_token_invalid');
    }
}
