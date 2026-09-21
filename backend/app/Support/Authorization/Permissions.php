<?php

namespace App\Support\Authorization;

/**
 * Minimal Sprint 1 permission set. Keys are stored (as booleans) in `roles.permissions_json`
 * and checked via Gate::authorize('<permission>') / User::hasPermission('<permission>'),
 * always scoped to the *current organization* (App\Support\Tenancy\TenantContext) — never
 * globally. Expected to grow substantially in later sprints (BOQ, pricing, proposals,
 * contracts, payments, change orders); keep this list append-only where practical so existing
 * role permissions_json blobs don't need retroactive migration.
 */
final class Permissions
{
    /** Manage organization-level settings (profile, branding, settings_json). */
    public const MANAGE_ORGANIZATION = 'manage_organization';

    /** Invite/remove members, change member roles or membership status. */
    public const MANAGE_MEMBERS = 'manage_members';

    /** Create/update/delete clients and their properties. */
    public const MANAGE_CLIENTS = 'manage_clients';

    /** Create/update/delete projects. */
    public const MANAGE_PROJECTS = 'manage_projects';

    /** Create/update leads and convert them to clients/projects. */
    public const MANAGE_LEADS = 'manage_leads';

    /**
     * View cost/pricing/margin data. Not used to gate anything in Sprint 1 (no pricing exists
     * yet) — seeded now so the Pricing sprint can rely on it existing in every role's
     * permissions_json without a data migration.
     */
    public const VIEW_FINANCIALS = 'view_financials';

    public const ALL = [
        self::MANAGE_ORGANIZATION,
        self::MANAGE_MEMBERS,
        self::MANAGE_CLIENTS,
        self::MANAGE_PROJECTS,
        self::MANAGE_LEADS,
        self::VIEW_FINANCIALS,
    ];
}
