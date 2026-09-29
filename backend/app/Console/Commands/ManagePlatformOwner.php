<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * `php artisan platform:owner {email} [--revoke]` — grants or revokes the BRD v3 §5/§20
 * "Platform Owner" flag for an existing user. Deliberately console-only, same posture as
 * RoleSeeder's global template roles: there is no HTTP endpoint anywhere in this app that can
 * grant this flag (a platform-owner-gated endpoint could only ever grant it to someone who
 * already has it, which is a pointless self-referential permission model) — only someone with
 * shell/deploy access can mint the first Platform Owner.
 */
class ManagePlatformOwner extends Command
{
    protected $signature = 'platform:owner {email} {--revoke : Revoke platform owner access instead of granting it}';

    protected $description = 'Grant or revoke platform owner access for a user by email';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $revoke = $this->option('revoke');
        $user->forceFill(['is_platform_owner' => ! $revoke])->save();

        $this->info($revoke
            ? "Revoked platform owner access from {$email}."
            : "Granted platform owner access to {$email}.");

        return self::SUCCESS;
    }
}
