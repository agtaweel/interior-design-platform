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

## Sprint 3 scope (complete)

Pricing layers on top of Sprint 2's BOQ — not proposals (Sprint 4). The ERD's `pricing_rules`
table is project-scoped and deliberately underspecified in the PRD; the design below resolves
that ambiguity once so every agent this sprint builds the same mental model.

**How project-level pricing relates to Sprint 2's per-item pricing** (this is the key design
decision): each `boq_item` already carries a manually-set `client_unit_price` (Sprint 2) — that's
the designer's line-item pricing. `pricing_rules` do NOT replace that; they add
organization/project-wide layers **on top of** the summed line-item pricing — e.g. a design fee
%, a supervision fee, a discount. Think of it as: line items are priced first (bottom-up), then
project-level rules adjust the total (top-down).

**Schema**: `pricing_rules` (id, project_id, name, type, method, value, base_selector, sort_order,
active):
- `type` enum: `markup` | `fee` | `discount` (supervision is just a fee named "Supervision" —
  per the locked product decision to keep the base flexible/configurable rather than hardcoding
  what supervision is computed on).
- `method` enum: `percentage` | `fixed_amount`.
- `value`: decimal — the percentage (e.g. 15.00 meaning 15%) or fixed EGP amount.
- `base_selector` enum: `boq_direct_cost` (sum of item direct costs — for cost-plus-style
  markups), `boq_client_subtotal` (sum of item client_totals — a fixed anchor, unaffected by
  other rules), or `running_subtotal` (the total *after* previously-applied rules — for
  cascading/compounding rules, e.g. a discount applied after fees). Order matters: rules apply
  in `sort_order` sequence.
- `active`: boolean — inactive rules are ignored by recalculation but not deleted (keeps history
  visible/re-enable-able).
- Also add cache columns to `projects` (or a small one-row-per-project cache table, your
  judgment) to store the last computed `direct_cost_total`, `client_subtotal`,
  `markup_total`, `fees_total`, `discount_total`, `grand_total`, `priced_at` — this is what
  populates `ProjectResource`'s `financials.value` field (currently hardcoded 0) without
  recomputing on every dashboard read. Recalculation is an explicit action (`POST
  /projects/{id}/pricing/recalculate`), not automatic on every BOQ edit — matches the PRD's
  explicit-recalculate-endpoint design and avoids recomputing on every keystroke.

**Recalculation algorithm** (`POST /projects/{id}/pricing/recalculate`):
1. `direct_cost_total` = sum of non-archived `boq_items.direct_cost`.
2. `client_subtotal` = sum of non-archived `boq_items.client_total` (the fixed anchor for
   `boq_client_subtotal`-based rules).
3. `running_subtotal` starts at `client_subtotal`.
4. For each active rule in `sort_order`: resolve `base` per `base_selector`; `amount = method ==
   percentage ? base * value/100 : value`; if `type == discount`, amount is subtracted (store the
   rule's contribution as a signed or unsigned number — your judgment, document which); add to
   `running_subtotal`; accumulate into `markup_total`/`fees_total`/`discount_total` by `type`.
5. `grand_total` = final `running_subtotal` — this is the project's client-facing price.
6. Persist the cache columns/row. `gross_profit` in `ProjectResource.financials` stays deferred to
   Sprint 6 (needs *actual* expenses, not estimated) — do not populate it from
   `grand_total - direct_cost_total`, that would be an estimate mislabeled as actual profit.
   `value` in `financials` SHOULD now populate from `grand_total`.

**API** (PRD §3 "BOQ & pricing" group, pricing portion): `POST
/projects/{id}/pricing/recalculate`, `GET /projects/{id}/pricing/breakdown` (internal view: full
line-by-line rule breakdown with running subtotal after each step, per PRD's "show formulas
clearly" requirement) — plus whatever CRUD routes are needed to manage `pricing_rules` themselves
(not explicit in the PRD table but required to configure anything; keep them RESTful and
consistent with existing route style, e.g. `GET/POST /projects/{id}/pricing/rules`, `PATCH/DELETE
/pricing/rules/{id}`).

**Client-facing exposure**: exactly like Sprint 2's BOQ resource split, build (or extend) a
client-facing pricing view now that returns ONLY `grand_total` (no cost breakdown, no rule list,
no margin) even though there's no client-facing consumer yet until Sprint 4's client portal —
this is the seam Sprint 4 will plug into.

**UX**: S08 Pricing Panel only, per PRD — direct cost, markup layers, design fee, supervision,
discount, final client price; toggle internal/client view; "never leak internal margin to
client" in the UI too (the toggle should be a real internal-only affordance, not just a display
mode a client user could flip). Wire it into the existing project in-project nav (the "BOQ &
Pricing" tab already exists from Sprint 2 — this sprint fills in the pricing half of that tab,
likely as a panel alongside or below the BOQ grid rather than a separate route, your judgment).

Stop and checkpoint with the user after this sprint before starting Proposals (Sprint 4).

## Sprint 4 scope (current)

Proposals: versioning, immutable snapshots, send + OTP-gated public approval, PDF, and the public
client portal (S11). This is the largest sprint yet and touches almost every prior layer (BOQ,
pricing, tenancy, the `SignedLinkService` primitive built in Sprint 1, and Sprint 3's
`ProjectPricingClientResource` seam). The design below resolves several real ambiguities in the
ERD/PRD once, so every agent works from the same model — read this whole section before writing
code, don't re-derive from the PDF alone.

### Schema

1. **`proposal_versions`** (project-scoped indirectly via project_id, per the established
   pattern): id, project_id, version_no (integer, unique per project, auto-incrementing per
   project starting at 1), status (`draft` | `sent` | `approved` | `changes_requested` —
   plain string per the established no-DB-enum convention), subtotal, markup_total, fees_total,
   discount_total, grand_total (decimal(14,2), mirroring Sprint 3's pricing cache columns —
   populated by copying the project's current pricing breakdown at creation time, not
   recalculated later), `content_json` (jsonb — cover note, scope text, exclusions, timeline,
   terms, payment plan description; the ERD doesn't enumerate these as columns, so bundle them
   here per S09's "Cover, scope, BOQ presentation, exclusions, timeline, terms, payment schedule,
   branding" requirement), `snapshot_json` (jsonb — the full frozen record: project/client/org
   info, every proposal_item, the pricing rule breakdown, and `content_json`'s contents at
   send/approve time — this is what makes the sent/approved version legally/commercially
   immutable even if the project's BOQ or pricing rules change afterward), created_by
   (user_id), sent_at, approved_at (both nullable timestamps). Wire `Auditable`.
2. **`proposal_items`** (id, proposal_version_id, source_boq_item_id nullable [traceability back
   to the BOQ item it was copied from — nullable because a future manually-added proposal line
   with no BOQ source should still be possible], description, quantity, unit, unit_price,
   line_total). These are a **frozen copy** made at proposal-version-creation time — never
   re-read from `boq_items` after creation, and never mutated once the parent version leaves
   `draft` status (see "Immutability rule" below). NEVER include cost/margin fields here — this
   table is inherently client-facing shaped (matches what S11 shows), unlike `boq_items`.
3. **`approvals`** (per the ERD, not yet built in any prior sprint): id, organization_id,
   project_id, entity_type (string, e.g. `"proposal_version"` — will also be used by change
   orders in Sprint 7, so keep it generic now), entity_id, approver_type (`client` | `internal`),
   user_id (nullable — null for client approvals since a client isn't a `users` row), status
   (`approved` | `changes_requested` | `rejected`), comment, approved_at, ip_address. Every
   public approve/request-changes action creates one row here — this is what the PRD's
   `approval_id` in the approve response refers to.
4. **OTP + idempotency mechanism** (not in the ERD at all — needed to implement the locked
   decision "OTP-based approval" and the NFR "idempotency keys for money-moving POSTs"; design it
   generically now since Sprint 7's change-order public approval will need the identical
   mechanism — do not build something proposal-specific that has to be redone):
   - Reuse Sprint 1's `signed_links` table/`SignedLinkService` for the token in the public URL
     (`/public/proposals/{token}`) — one signed link per sent version, purpose e.g.
     `"proposal_approval"`, entity = the proposal_version id.
   - Add an `otp_challenges` table: id, signed_link_id (FK), code_hash (never store the raw
     code), expires_at, verified_at (nullable), attempts (integer, default 0 — cap and lock out
     after e.g. 5 failed attempts, return a clear error rather than allowing brute force). A new
     OTP is generated whenever a proposal is (re)sent. Since there's no WhatsApp/email
     integration this MVP (locked decision — links are shared manually by staff), the OTP code
     itself is also just displayed to staff in the internal UI to relay manually, exactly like
     the link.
   - Add an `idempotency_keys` table: id, scope (string, e.g. `"proposal_approve:{proposal_version_id}"`),
     key (the client-supplied `Idempotency-Key` header value), response_status, response_body
     (jsonb), created_at. Unique on (scope, key). **Behavior**: if a request includes an
     `Idempotency-Key` header matching a stored one for that scope, replay the stored response
     verbatim regardless of current state. If the header is absent, or present but new, apply
     normal state-conflict rules — i.e. approving an already-approved version without a matching
     idempotency key returns `409 PROPOSAL_ALREADY_APPROVED` (per PRD §3.3's exact error example).
     This reconciles the PRD's two seemingly-contradictory statements ("must be idempotent" +
     the 409-already-approved error example) — document this reconciliation in code, don't just
     implement one half.

### Immutability rule (critical NFR)

A `proposal_version` is editable (PATCH content, regenerate items from current BOQ/pricing) only
while `status == 'draft'`. The moment it's sent (`status` becomes `'sent'`), it is locked —
`content_json`, `proposal_items`, and the pricing totals become read-only, and `snapshot_json` is
finalized. To make a change after sending, the designer creates a **new** proposal_version
(`version_no + 1`), which starts as a fresh draft seeded from current BOQ/pricing (not from the
old version — always pull fresh data into a new draft, never clone a stale one). Approval further
locks it (`approved`) but the immutability boundary is already at `sent`, not `approved` — don't
wait until approval to freeze it, since a client could be viewing/reviewing a "sent" version and
it must not change under them.

### API

- `POST /projects/{id}/proposals` — create a new draft version: snapshot current BOQ (via
  non-archived `boq_items`) into `proposal_items`, current pricing breakdown into the totals
  columns (call the Sprint 3 `PricingCalculator` directly rather than re-deriving the math), and
  accept `content_json` in the request body for the editorial content.
- `GET /projects/{id}/proposals` — list versions (id, version_no, status, grand_total, created_by,
  sent_at, approved_at) for S10's version history.
- `GET /proposals/{id}` — full internal detail (items, content, snapshot, totals).
- `PATCH /proposals/{id}` — update `content_json` (and re-snapshot items/pricing if still BOQ-
  linked) — only while `status == 'draft'`; `409` otherwise.
- `POST /proposals/{id}/send` — transitions `draft` → `sent`, finalizes `snapshot_json`, creates
  the `signed_links` row + `otp_challenges` row, sets `sent_at`. Returns the public URL and the
  OTP code in the response (internal-only response — this is how staff get what to relay
  manually, per the locked WhatsApp decision). Requires `manage_boq` permission (proposals are
  part of the same commercial workflow) — or introduce a `manage_proposals` permission if you
  think the distinction is warranted; your judgment, document the choice.
- `GET /public/proposals/{token}` — public, token-authenticated (no Sanctum), returns the
  client-shaped view: project/client header info, `content_json`, `proposal_items` (description/
  quantity/unit/unit_price/line_total — no cost fields, matches `boq_items`' existing
  internal/client resource split), and **only** `grand_total` from the pricing block (reuse/
  extend `ProjectPricingClientResource`'s shape) — never subtotal/markup/fees/discount
  breakdown, never `source_boq_item_id`. Rate-limit this endpoint (a public, enumerable-by-token
  surface).
- `POST /public/proposals/{token}/approve` — body `{name, comment?, otp}`, optional
  `Idempotency-Key` header. Validates token (not expired/revoked), validates OTP (hash match,
  not expired, attempt count under limit — increment attempts on failure), then in a DB
  transaction: sets `proposal_versions.status = 'approved'`, `approved_at`, creates an
  `approvals` row (`approver_type = 'client'`, `user_id = null`, capture `ip_address`), marks the
  `otp_challenges.verified_at`. Returns `{status: 'approved', approved_at, approval_id,
  contract_conversion_available: true}` (per PRD §3.2's exact example — `contract_conversion_available`
  is just `true` here since actual contract creation is Sprint 5, not built yet).
- `POST /public/proposals/{token}/request-changes` — body `{name, comment}`, no OTP required
  (lower stakes than approval — a comment, not a binding commercial action). Sets
  `proposal_versions.status = 'changes_requested'`, creates an `approvals` row
  (`status = 'changes_requested'`). Does NOT lock the version any further than `sent` already
  did — designer creates a new draft version in response.
- **PDF**: not in the PRD's route table but required by the Definition of Done ("generated as
  PDF"). Add `GET /proposals/{id}/pdf` (internal, authenticated) and `GET
  /public/proposals/{token}/pdf` (public, same token auth as the portal). Use `barryvdh/laravel-
  dompdf` (pure-PHP, no headless-browser dependency needed in the Docker image) rendering a
  Blade view built from the same client-shaped data as the public JSON endpoint — never render
  cost/margin fields into the PDF either.

### UX

- **S09 Proposal Editor** (internal): author `content_json` (cover, scope, exclusions, timeline,
  terms, payment plan description), preview exactly what the client will see (reuse the public
  portal's rendering or a close approximation), a "Send" action that surfaces the copyable public
  link + OTP code prominently (staff need to actually copy these to relay via WhatsApp/email
  manually).
- **S10 Proposal Version History** (internal): list of versions with status/created-by/timestamps,
  a way to view any past version's frozen content (read-only for non-draft versions).
- **S11 Client Proposal Portal** (public, mobile-first, NEW top-level route not under the internal
  app's auth — e.g. `/p/proposals/[token]` — no dashboard nav, no login): project header, scope,
  selected BOQ (client-shaped line items), total, payment plan, terms, Approve (prompts for name +
  OTP) / Request Changes (prompts for name + comment) actions. Never render internal cost data —
  this is the client-facing surface the entire "never leak margin" NFR has been building toward
  since Sprint 2; treat it with the same care as the backend's resource-splitting discipline.

Stop and checkpoint with the user after this sprint before starting Approval + Contract (Sprint 5) —
note Sprint 5 is literally "the rest of" what this sprint's `approve` endpoint stubs
(`contract_conversion_available`), so keep the approvals/OTP mechanism generic and reusable.
