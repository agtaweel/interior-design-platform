<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Seeder;

/**
 * Global template roles (organization_id null) every organization can assign members to
 * without first defining custom roles. See App\Models\Role's docblock and the Sprint 1
 * RBAC design in App\Support\Authorization\Permissions.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsert('Owner', array_fill_keys(Permissions::ALL, true));

        $this->upsert('Admin', [
            Permissions::MANAGE_ORGANIZATION => false,
            Permissions::MANAGE_MEMBERS => true,
            Permissions::MANAGE_CLIENTS => true,
            Permissions::MANAGE_PROJECTS => true,
            Permissions::MANAGE_LEADS => true,
            Permissions::VIEW_FINANCIALS => true,
        ]);

        $this->upsert('Designer', [
            Permissions::MANAGE_ORGANIZATION => false,
            Permissions::MANAGE_MEMBERS => false,
            Permissions::MANAGE_CLIENTS => true,
            Permissions::MANAGE_PROJECTS => true,
            Permissions::MANAGE_LEADS => true,
            Permissions::VIEW_FINANCIALS => false,
        ]);

        $this->upsert('Site Staff', array_fill_keys(Permissions::ALL, false));
    }

    private function upsert(string $name, array $permissions): void
    {
        Role::query()->updateOrCreate(
            ['organization_id' => null, 'name' => $name],
            ['permissions_json' => $permissions],
        );
    }
}
