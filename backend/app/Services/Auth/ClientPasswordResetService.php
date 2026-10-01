<?php

namespace App\Services\Auth;

use App\Mail\PasswordResetMail;
use App\Models\ClientUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * BRD v4 "Client Marketplace" — mirrors PasswordResetService exactly, but targets `ClientUser`
 * and the separate `client_password_reset_tokens` table (see that migration's docblock for why
 * it isn't shared with the staff `password_reset_tokens` table). Reuses `PasswordResetMail` and
 * `PasswordResetException` as-is — both are already generic (recipient name/reset URL/expiry;
 * reason string), with nothing staff-specific to duplicate.
 */
class ClientPasswordResetService
{
    private const TOKEN_LENGTH = 64;

    public function sendResetLink(string $email): void
    {
        $clientUser = ClientUser::query()->where('email', $email)->first();

        if (! $clientUser) {
            return;
        }

        DB::table('client_password_reset_tokens')->where('email', $email)->delete();

        $token = Str::random(self::TOKEN_LENGTH);

        DB::table('client_password_reset_tokens')->insert([
            'email' => $email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $resetUrl = sprintf(
            '%s/client/reset-password?token=%s&email=%s',
            config('app.frontend_url'),
            $token,
            urlencode($email),
        );

        try {
            Mail::to($email)->send(new PasswordResetMail($clientUser->name, $resetUrl, $this->expiryMinutes()));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @throws PasswordResetException
     */
    public function reset(string $email, string $token, string $newPassword): void
    {
        $row = DB::table('client_password_reset_tokens')->where('email', $email)->first();

        if (! $row) {
            throw new PasswordResetException('invalid');
        }

        if (Carbon::parse($row->created_at)->diffInMinutes(now()) > $this->expiryMinutes()) {
            DB::table('client_password_reset_tokens')->where('email', $email)->delete();

            throw new PasswordResetException('expired');
        }

        if (! Hash::check($token, $row->token)) {
            throw new PasswordResetException('invalid');
        }

        $clientUser = ClientUser::query()->where('email', $email)->first();

        if (! $clientUser) {
            throw new PasswordResetException('invalid');
        }

        $clientUser->forceFill(['password' => $newPassword])->save();

        DB::table('client_password_reset_tokens')->where('email', $email)->delete();

        $clientUser->tokens()->delete();
    }

    private function expiryMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }
}
