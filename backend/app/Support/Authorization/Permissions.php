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

    /**
     * BRD "Procurement & Supplier Intelligence": manage the supplier directory and purchase
     * order lifecycle (draft/sent/partially_received/received/cancelled), including recording
     * deliveries and actual received cost. Its own permission (not reused from MANAGE_BOQ)
     * because the BRD persona table lists "Procurement" as a distinct role from
     * Designer/Engineer — a design office may want a bookkeeper/procurement clerk who can
     * manage suppliers/POs without also being able to edit the BOQ itself. Gates BOTH reads
     * and writes (unlike MANAGE_BOQ, which only gates writes) — supplier pricing/actual-cost
     * data is exactly the kind of profit-adjacent data the BRD's persona table flags as
     * "Supplier/cost data" sensitive, same posture as VIEW_FINANCIALS gating Payment reads.
     */
    public const MANAGE_PROCUREMENT = 'manage_procurement';

    /**
     * BRD "Execution" module: tasks, milestones, site reports, photos. Its own permission
     * (not MANAGE_BOQ) because Execution is a day-to-day site-engineer workflow distinct from
     * BOQ/pricing editing — the BRD persona table gives "Site Engineer" tasks/site
     * reports/photos/snags without BOQ/pricing access. Also gates snag (defect/punch-list)
     * mutations, since snags are raised during the same execution workflow by the same people.
     * Reads require only an active membership, matching this codebase's default posture
     * (execution progress is operational, not financial, data).
     */
    public const MANAGE_EXECUTION = 'manage_execution';

    /**
     * BRD v3 §12 "Reconciliation & Closeout": force-closing a project's financial state
     * (CLOSED, bypassing the normal ACTIVE -> FINANCIAL_PENDING -> READY_FOR_CLOSE gate that
     * requires outstanding=0) is a deliberate override of the platform's own financial-integrity
     * check, not a routine action — the BRD's own wording requires this be restricted to
     * "Owner/Admin", not the general MANAGE_BOQ financial-mutation tier every other
     * commercial-workflow permission in this codebase reuses. A dedicated permission (seeded
     * true only for Owner/Admin in RoleSeeder) encodes that restriction as an actual permission
     * check rather than special-casing role NAMES in controller code, keeping this codebase's
     * "permissions, not role names, are what business logic checks" RBAC design intact. Normal
     * (non-force) closeout transitions reuse Permissions::MANAGE_BOQ, same tier as
     * Contracts/Payments/Change Orders.
     */
    public const MANAGE_FINANCIAL_CLOSEOUT = 'manage_financial_closeout';

    public const ALL = [
        self::MANAGE_ORGANIZATION,
        self::MANAGE_MEMBERS,
        self::MANAGE_CLIENTS,
        self::MANAGE_PROJECTS,
        self::MANAGE_LEADS,
        self::VIEW_FINANCIALS,
        self::MANAGE_BOQ,
        self::MANAGE_PROCUREMENT,
        self::MANAGE_EXECUTION,
        self::MANAGE_FINANCIAL_CLOSEOUT,
    ];
}
