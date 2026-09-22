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

    /**
     * Create/update/archive a project's BOQ (categories, items), apply office templates to a
     * project's BOQ, and manage the organization's BOQ templates themselves, plus BOQ CSV
     * import. Sprint 2 introduces this as its own permission rather than reusing
     * MANAGE_PROJECTS: BOQ editing is a distinct day-to-day workflow (designers/estimators)
     * from project lifecycle management (status, membership, services), and later sprints
     * (pricing, proposals) will likely want to grant/withhold BOQ access independently of
     * full project-admin rights. Reads (GET boq / GET templates / CSV export) require no
     * extra permission beyond an active membership, matching every other read endpoint in
     * this codebase.
     *
     * Sprint 4 (Proposals) also reuses this permission for proposal create/update/send rather
     * than introducing a `manage_proposals` permission: PROJECT_CONTEXT.md explicitly left the
     * choice to backend-api-engineer's judgment. Reusing MANAGE_BOQ was chosen because (a) it
     * follows the precedent PricingRuleController already set for the identical "your
     * judgment" call in Sprint 3 (pricing sits "on top of" BOQ, proposals sit on top of
     * pricing — same designer/estimator workflow, not a separate approval role in this MVP),
     * and (b) introducing a new permission key would require a data migration to backfill
     * every already-seeded organization's custom Role.permissions_json blobs (RoleSeeder's
     * Owner/Admin/Designer rows already have manage_boq=true from Sprint 2/3) before anyone
     * could actually use this sprint's endpoints — reusing MANAGE_BOQ needs no such migration.
     */
    public const MANAGE_BOQ = 'manage_boq';

    public const ALL = [
        self::MANAGE_ORGANIZATION,
        self::MANAGE_MEMBERS,
        self::MANAGE_CLIENTS,
        self::MANAGE_PROJECTS,
        self::MANAGE_LEADS,
        self::VIEW_FINANCIALS,
        self::MANAGE_BOQ,
    ];
}
