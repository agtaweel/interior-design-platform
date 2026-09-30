<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a fixed-credential Platform Owner (BRD v3 §5/§20) account so every environment — local
 * docker-compose, a fresh demo, or a Northflank/Render redeploy — always has a working cross-org
 * admin login, without anyone needing shell access to run `php artisan platform:owner` by hand.
 *
 * `is_platform_owner` is deliberately excluded from User's #[Fillable] (see that model's
 * docblock) so it can't be mass-assigned from request input; it's set here via forceFill()
 * after the user record exists, the same technique app/Console/Commands/ManagePlatformOwner.php
 * uses — this seeder doesn't introduce a second mechanism, it just automates the existing one.
 *
 * Safe to run repeatedly (updateOrCreate + idempotent forceFill), same posture as RoleSeeder.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'admin@fitout.example'],
            [
                'name' => 'Fitout Platform Admin',
                'password' => 'FitoutAdmin#2026',
                'status' => 'active',
            ],
        );

        $user->forceFill(['is_platform_owner' => true])->save();
    }
}
