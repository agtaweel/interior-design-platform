<?php

namespace App\Services\Auth;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Platform Readiness Review finding #04: staff had no self-service way back into their account
 * if they forgot their password — someone with database access had to reset it by hand.
 *
 * A custom, small implementation against the `password_reset_tokens` table that already exists
 * in this app's base migration (Laravel's default stub creates it, but nothing used it until
 * now) — deliberately NOT Laravel's built-in Password::sendResetLink()/broker, which requires
 * User to use the framework's Illuminate\Notifications\Notifiable trait. That trait defines its
 * own notifications() relation, which would collide with this app's own, semantically different
 * User::notifications() (the in-app Notification inbox, see that model's docblock) — reusing the
 * table but not the broker avoids that collision entirely while staying just as simple.
 *
 * Same hashing choice as OtpChallengeService (bcrypt via the Hash facade, never a fast hash) for
 * the same consistency reasoning: this codebase already uses Hash::make()/Hash::check()
 * everywhere else a secret is checked, and call volume here is inherently low.
 */
class PasswordResetService
{
    private const TOKEN_LENGTH = 64;

    /**
     * Always succeeds from the caller's perspective, whether or not the email belongs to a real
     * user — the controller must return the same generic response either way (see
     * AuthController::forgotPassword()) so a public, unauthenticated endpoint can't be used to
     * enumerate which emails have accounts.
     */
    public function sendResetLink(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return;
        }

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        $token = Str::random(self::TOKEN_LENGTH);

        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $resetUrl = sprintf(
            '%s/reset-password?token=%s&email=%s',
            config('app.frontend_url'),
            $token,
            urlencode($email),
        );

        try {
            Mail::to($email)->send(new PasswordResetMail($user->name, $resetUrl, $this->expiryMinutes()));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @throws PasswordResetException
     */
    public function reset(string $email, string $token, string $newPassword): void
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $row) {
            throw new PasswordResetException('invalid');
        }

        if (Carbon::parse($row->created_at)->diffInMinutes(now()) > $this->expiryMinutes()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            throw new PasswordResetException('expired');
        }

        if (! Hash::check($token, $row->token)) {
            throw new PasswordResetException('invalid');
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            throw new PasswordResetException('invalid');
        }

        $user->forceFill(['password' => $newPassword])->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // Every existing Sanctum token is revoked on a password reset — the whole point of
        // resetting is "I no longer trust whoever might currently be signed in as me."
        $user->tokens()->delete();
    }

    private function expiryMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }
}
