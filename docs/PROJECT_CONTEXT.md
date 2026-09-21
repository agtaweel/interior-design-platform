# Interior Design & Finishing Management Platform — Project Context

Source: `Interior_Design_Platform_PRD_ERD_API_UX.pdf` (Specification v1.0). This file is the
condensed, load-bearing reference for every subagent on this project — read it before touching
code so you don't have to re-derive decisions already made.

## What this product is

A multi-tenant SaaS platform for interior design/finishing companies (Egypt market, EGP default
currency) to run their business end to end: leads → clients/properties → projects → BOQ (bill of
quantities) & pricing → proposals → client approval → contracts → payments → change orders →
execution (tasks/site reports/snagging) → handover. Includes a mobile-first client portal reached
via WhatsApp links.

## Locked product decisions (PRD §8 recommended defaults — confirmed by user)

These were explicitly called out in the spec as needing to be locked before development. Per user
instruction, we are using the PRD's own recommended defaults:

1. **Pricing/markup visibility**: keep configurable per organization — support multiple internal
   markup layers, but the client-facing proposal only ever shows the final configured client unit
   price. Never leak internal cost/margin fields to client-facing or public serializers.
2. **Tax handling**: MVP is tax-exclusive by default (configurable), avoid building full tax
   engine complexity until real customer workflows validate it.
3. **Contract signing**: MVP approval is via secure link + OTP, not a formal e-signature vendor
   integration.
4. **Payments**: recorded only in MVP (schedules + payment records + receipts). The platform does
   NOT initiate payment collection/processing.
5. **WhatsApp**: links are shared manually by staff (copy/send); no WhatsApp Business API
   integration required for MVP. Links must be short-lived/revocable and never expose internal
   IDs, supplier data, costs, or profit.
6. **Multi-branch/multi-brand**: single-office SaaS for MVP, but keep organization settings
   flexible (`organizations.settings_json`) so multi-branch isn't architecturally foreclosed.

## Stack (confirmed)

- **Backend**: Laravel (PHP) REST API, PostgreSQL, Redis for queues/cache.
- **Auth**: Laravel Sanctum (token/session), tenant-scoped via `organization_id` on every
  business table or a guaranteed parent relationship.
- **Frontend**: Next.js (React) + TypeScript, Arabic/English from day one with RTL support,
  desktop/tablet-first for the internal app, mobile-first for the client portal.
- **Files**: object storage (S3-compatible) with signed URLs; virus scanning + size/type limits.
- **Money**: decimal types only, never floating point. EGP formatting, Egyptian date/phone formats.

## Non-functional requirements (apply everywhere)

- Tenant isolation on every organization-scoped query; RBAC; secure token/session handling;
  signed client links; audit trail for all commercial changes.
- Dashboard/API requests target <500ms at normal load; BOQ editing feels immediate
  (optimistic/local calc where safe).
- Transactional (DB transaction) updates for: proposal approval, contract conversion, payment
  recording, change-order application.
- Idempotency keys required for payment creation and other money-moving POSTs.
- Structured logs, metrics, error tracking, request IDs.
- Consistent error envelope: `{"error":{"code":"...","message":"...","details":{}}}`.
  HTTP codes: 400 validation, 401 unauthenticated, 403 unauthorized, 404 not found,
  409 state conflict/idempotency conflict, 422 business-rule validation, 429 rate limited,
  500 unexpected.
- Financial integrity: DB transactions when applying approvals, converting proposals to
  contracts, recording payments, applying approved change orders. Store proposal/contract
  **snapshots** so later BOQ edits cannot alter historical commercial documents (approved
  proposal versions are immutable/read-only).

## ERD summary (see PRD §2 for full field lists)

Core tables: `organizations`, `users`, `organization_members`, `roles`, `leads`, `clients`,
`properties`, `projects`, `project_members`, `project_services`, `boq_categories`, `boq_items`,
`rooms`, `pricing_rules`, `proposal_versions`, `proposal_items`, `contracts`, `payment_schedules`,
`payments`, `change_orders`, `change_order_items`, `expenses`, `suppliers`, `purchase_orders`,
`tasks`, `site_reports`, `documents`, `approvals`, `snags`, `handover_records`, `notifications`,
`audit_logs`.

Core relationships: Organization 1—N Users (via `organization_members`); Client 1—N Properties;
Client 1—N Projects; Property 1—N Projects; Project 1—N BOQ categories/items, rooms, proposal
versions, tasks, expenses, change orders, site reports; Proposal Version 1—N Proposal Items;
Approved Proposal Version 1—0..1 Contract; Contract 1—N Payment Schedules; Payment Schedule 1—N
Payments; Change Order 1—N Change Order Items.

Recommended indexes: `organization_id` on all tenant tables; `clients(phone)`;
`projects(organization_id,status)`; `boq_items(project_id,category_id)`;
`proposal_versions(project_id,version_no)`; `payments(project_id,paid_at)`;
`payment_schedules(contract_id,due_date)`; `audit_logs(organization_id,entity_type,entity_id,created_at)`.

## REST API summary (see PRD §3 for full spec)

Base URL `/api/v1`, JSON, auth via token/session, all endpoints require organization context
except public client approval links (`/public/...`, token-based, no auth header).

Groups: Auth & users, Clients & properties, Projects, BOQ & pricing, Proposals (incl. public
approve/request-changes), Contracts & payments, Change orders, Execution (Phase 2: tasks,
site-reports, snags), Documents.

Full endpoint list is in `docs/API_SPEC.md` (to be extracted verbatim by the backend agent when
implementing each sprint's routes) — treat the PRD PDF §3 tables as source of truth for exact
paths/methods/purposes.

## UX summary (see PRD §4 for full 22-screen spec)

Design direction: professional but simple, desktop/tablet-first for internal app, mobile-first
for client portal. Designer must be able to build a quotation without navigating many pages.

Key screens for Sprint 1 (Foundation): S01 Login/Invitation, S02 Main Dashboard, S03 Leads,
S04 Client Profile, S05 Property, S06 Project Overview.

Later-sprint screens: S07 BOQ Builder, S08 Pricing Panel, S09 Proposal Editor, S10 Proposal
Version History, S11 Client Proposal Portal, S12 Contract, S13 Payments, S14 Change Orders,
S15 Client Project Portal, S16 Tasks/Site, S17 Site Report, S18 Expenses/Suppliers,
S19 Snagging, S20 Handover, S21 Reports, S22 Settings.

Navigation (desktop): Dashboard / Leads / Clients / Projects / Suppliers / Reports / Settings.
Within a project: Overview / BOQ & Pricing / Proposal / Contract / Payments / Change Orders /
Execution / Documents / Activity.
Navigation (mobile client portal): Overview / Proposal / Approvals / Payments / Progress /
Documents.

## MVP delivery order (PRD §6)

1. **Foundation** — Auth, organizations, roles, clients, properties, projects, audit/logging. ← *current sprint*
2. **BOQ** — Categories, rooms, items, templates, calculations, import/export.
3. **Pricing** — Cost layers, markup rules, fees, discounts, client/internal views.
4. **Proposals** — Proposal editor, versions, PDF, send, public client portal.
5. **Approval + Contract** — OTP/approval, immutable approval, contract conversion, terms.
6. **Payments** — Schedules, payment recording, receipts, receivables dashboard.
7. **Change Orders** — Create/send/approve/apply, contract and financial impact.
8. **Polish** — Reports, permissions hardening, notifications, QA, analytics, deployment.

## Definition of Done for MVP (PRD §7)

- A designer can create a client, property and project.
- A designer can create a BOQ from scratch or template and calculate internal/client pricing.
- A proposal can be versioned, generated as PDF and shared with a client.
- A client can securely review and approve or request changes.
- An approved proposal can become a contract without losing the approved commercial snapshot.
- Payment schedules can be created and actual payments recorded with receipts.
- Change orders can alter commercial value only through an auditable workflow.
- Owner dashboard shows project value, collected, outstanding, actual cost and gross profit.
- Role permissions prevent clients and site users from seeing internal cost/profit.
- All commercial mutations are audited and historical approved documents remain immutable.

## Local dev environment (already running)

- `docker-compose.yml` at repo root defines three services: `postgres` (5440→5432, db
  `interior_design_platform`, user `admin`/`password`), `redis` (6390→6379), and `app` (the
  Laravel backend, built from `backend/Dockerfile`, port 8000, auto-runs
  `composer install && php artisan migrate --force && php artisan serve`).
- The `app` container mounts `./backend` live, so editing files on the host is immediately
  reflected — no rebuild needed unless `Dockerfile` itself or system deps change.
- Inside the Docker network, `backend/.env` points `DB_HOST=postgres` `DB_PORT=5432` and
  `REDIS_HOST=redis` `REDIS_PORT=6379` (container-to-container, not the host-mapped ports).
- To run artisan commands (migrations, tests, tinker): `docker compose exec app php artisan ...`
  or `docker compose exec app php artisan test`.
- From the host (e.g. `psql` for manual inspection), use `127.0.0.1:5440`.
- Frontend (`frontend/`) is a plain Next.js app, not yet dockerized — run with `npm run dev`
  inside `frontend/` (port 3000 by default).

## Sprint 1 scope (complete)

Foundation only: auth, organizations, roles/RBAC, organization_members, clients, properties,
projects, project_members, audit_logs — plus screens S01–S06 and their supporting tests. 67
backend tests passing. See git log for the sequence of commits.

## Sprint 2 scope (current)

BOQ only — not pricing/markup (that's Sprint 3). In scope:

- **Schema**: `rooms` (id, project_id, name, area_m2, sort_order), `boq_categories` (id,
  project_id, parent_id, name, sort_order — self-referential for nested categories),
  `boq_items` (id, project_id, category_id, room_id, name, description, quantity, unit,
  material_unit_cost, labor_unit_cost, other_unit_cost, client_unit_price, supplier_id, notes,
  sort_order). `supplier_id` references the `suppliers` table which doesn't exist yet (Sprint 6
  scope) — make it a nullable unconstrained column for now (no FK) rather than building supplier
  management early; add the FK constraint when `suppliers` lands.
- **Templates**: the PRD's UX spec (§4.1) requires starting a BOQ "from office template, then
  customize without changing the master template," but the ERD has no explicit template tables.
  Resolve this by adding organization-scoped template tables that mirror the project-scoped ones
  (e.g. `boq_template_categories`, `boq_template_items` — no `project_id`, scoped by
  `organization_id` instead) and an "apply template to project" operation that **copies**
  template rows into a project's `boq_categories`/`boq_items` (new rows, new ids) rather than
  referencing the template — this is what "without changing the master template" requires:
  editing the cloned project BOQ must never mutate the template.
- **Calculations**: per-item `direct_cost = (material_unit_cost + labor_unit_cost +
  other_unit_cost) * quantity` and `client_total = client_unit_price * quantity`. This is the
  per-item math only — organization-wide markup layers/fees/discounts (`pricing_rules` table) are
  Sprint 3. Category and room subtotals roll up from items.
- **Client-facing exposure**: even in Sprint 2, never return `material_unit_cost`,
  `labor_unit_cost`, `other_unit_cost`, or `supplier_id` in any client-facing/public serializer —
  this rule starts now, not when Sprint 3's pricing views are built.
- **Import/export**: CSV (not full Excel binary) import of BOQ items into a project, and CSV
  export of a project's current BOQ. Note this as a scope decision — the PRD says "Excel/CSV
  import/export," CSV covers the accounting/offline-review use case without adding a
  spreadsheet-parsing dependency; revisit if a customer specifically needs .xlsx.
- **API** (PRD §3 "BOQ & pricing" group, BOQ portion only): `GET /projects/{id}/boq`,
  `POST /projects/{id}/boq/categories`, `POST /projects/{id}/boq/items`,
  `PATCH /boq/items/{itemId}`, `DELETE /boq/items/{itemId}` (archive/soft-delete, not hard
  delete — BOQ items may already be referenced by a sent proposal in later sprints, so plan for
  a `deleted_at` / `archived_at` column now even though nothing reads it yet).
- **UX**: S07 BOQ Builder only, per PRD §4.1 "detailed interaction" notes — spreadsheet-like
  inline editing, keyboard navigation, duplicate row, drag/reorder, multi-select + bulk category
  assignment, quantity × unit price live recalculation without page refresh, room filter with
  optional room subtotals, draft state is freely editable (no approved-version concept exists
  yet — that's Sprint 4/5). Do NOT build S08 Pricing Panel yet.

Stop and checkpoint with the user after this sprint before starting Pricing (Sprint 3).
