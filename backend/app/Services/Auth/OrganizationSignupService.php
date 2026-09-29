<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Platform Readiness Review finding #01 — self-serve organization signup. Creates a brand-new
 * Organization + User in one transaction and makes that user the org's Owner immediately (status
 * 'active', not 'invited' — there's no inviter to approve them, they *are* the account holder),
 * reusing the same global 'Owner' template role RoleSeeder already seeds (organization_id null,
 * every Permissions::ALL flag true) rather than creating a new role per organization.
 */
class OrganizationSignupService
{
    public function signup(string $organizationName, string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($organizationName, $name, $email, $password) {
            $organization = Organization::create(['name' => $organizationName]);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'status' => 'active',
            ]);

            $ownerRole = Role::query()->whereNull('organization_id')->where('name', 'Owner')->firstOrFail();

            OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role_id' => $ownerRole->id,
                'status' => 'active',
            ]);

            return $user;
        });
    }
}
