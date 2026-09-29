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
            Permissions::MANAGE_BOQ => true,
            Permissions::MANAGE_PROCUREMENT => true,
            Permissions::MANAGE_EXECUTION => true,
            // BRD v3 §12: force-close is restricted to Owner/Admin specifically — see that
            // constant's docblock.
            Permissions::MANAGE_FINANCIAL_CLOSEOUT => true,
        ]);

        $this->upsert('Designer', [
            Permissions::MANAGE_ORGANIZATION => false,
            Permissions::MANAGE_MEMBERS => false,
            Permissions::MANAGE_CLIENTS => true,
            Permissions::MANAGE_PROJECTS => true,
            Permissions::MANAGE_LEADS => true,
            Permissions::VIEW_FINANCIALS => false,
            // Designers own the BOQ Builder day-to-day (PROJECT_CONTEXT.md S07) — they need
            // to create/edit/archive BOQ items and apply templates even though they don't see
            // financial rollups (VIEW_FINANCIALS is a separate, later-sprint concern for
            // margin visibility, not for editing cost inputs).
            Permissions::MANAGE_BOQ => true,
            // Procurement is a distinct persona in the BRD (a bookkeeper/procurement clerk
            // role) — a Designer/Engineer does not automatically manage suppliers/POs.
            Permissions::MANAGE_PROCUREMENT => false,
            // Designers/Engineers run execution day-to-day per the BRD persona table
            // ("Designer/Engineer: ...changes, execution").
            Permissions::MANAGE_EXECUTION => true,
            Permissions::MANAGE_FINANCIAL_CLOSEOUT => false,
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
