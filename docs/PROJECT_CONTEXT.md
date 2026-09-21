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

## Sprint 1 scope (this checkpoint)

Foundation only: auth, organizations, roles/RBAC, organization_members, clients, properties,
projects, project_members, audit_logs — plus screens S01–S06 and their supporting tests. Stop
and checkpoint with the user after this sprint before starting BOQ (Sprint 2).
