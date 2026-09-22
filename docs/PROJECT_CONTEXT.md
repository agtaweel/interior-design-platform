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
- **Known gotcha, confirmed real (not a misdiagnosis) on 2026-09-22**: editor-style atomic-save
  writes to `backend/` files (the kind Write/Edit tools make — write-to-temp-then-rename) do NOT
  reliably propagate into the `idp-app` container's view of the bind mount, even though the host
  file is correct. A plain in-place append (e.g. `echo >> file` from a shell) DOES propagate
  instantly — only the temp-file+rename pattern is affected. Symptom: you edit a file, `docker
  compose exec app grep ...` on that file inside the container shows the OLD content, and new
  routes/classes silently don't register (no error — `route:list` just doesn't show them).
  **Fix**: after editing existing `backend/` files (new files are unaffected), run `docker
  compose restart app` before trusting `artisan route:list`/`artisan test`/any live curl call
  against the server. Cheap (a few seconds) and safe — the `app` service's startup command
  re-runs `composer install && migrate --force` harmlessly on restart. Don't skip this and then
  conclude a route/class "doesn't exist" — verify against a fresh container first.

## Sprint 1 scope (complete)

Foundation only: auth, organizations, roles/RBAC, organization_members, clients, properties,
projects, project_members, audit_logs — plus screens S01–S06 and their supporting tests. 67
backend tests passing. See git log for the sequence of commits.

## Sprint 2 scope (complete)

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

## Sprint 4 scope (complete)

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

## Sprint 5 scope (complete)

Approval + Contract. The "approval" half is already done (Sprint 4's OTP-gated public approve
endpoint) — this sprint is really "contract conversion + terms," per the MVP delivery order's own
label. Do NOT build payment schedules or payment recording here — despite PRD §3 grouping
"Contracts & payments" as one API table section, the MVP delivery order (§6) puts "Schedules,
payment recording, receipts, receivables dashboard" in **Sprint 6**, not this one. `GET
/projects/{id}/financials` also belongs to Sprint 6 (it needs real payment data — collected/
outstanding — to be meaningful; this sprint doesn't touch it).

### Key design decision: what "contract signing" means for MVP

The locked product decision (PRD §8, PROJECT_CONTEXT.md's locked-decisions list, item 3) already
settled this: "MVP approval is via secure link + OTP, not a formal e-signature vendor
integration." Read literally, this means the **client's OTP approval of the proposal in Sprint 4
IS the signing act** — there is no second client-facing signing ceremony for the contract itself.
Concretely: contract creation is an **internal, authenticated** action (staff clicks "Convert to
Contract" after seeing `contract_conversion_available: true`), not a new public/token flow. The
contract is considered signed at creation time — set `signed_at = now()` on creation, no separate
"send for signature" step. This keeps Sprint 5 much smaller than Sprint 4 and is consistent with
the recommended-default's intent (avoid building e-signature complexity for MVP).

### Immutability boundary for contracts (distinct from proposals')

`contract_value` and `proposal_version_id` are permanently locked at creation — they must equal
the source proposal's `grand_total` exactly, never recalculated or edited. This is the ERD's
"Approved Proposal Version 1—0..1 Contract" relationship: one proposal converts to at most one
contract (enforce a unique constraint on `proposal_version_id`; attempting to convert an
already-converted proposal is a `409 CONTRACT_ALREADY_EXISTS`-style error, plain conflict
handling — no idempotency-key mechanism needed here since this is an authenticated internal
action, not the public money-moving surface Sprint 4's approve endpoint is).

`start_date`, `end_date`, and `terms_json`, by contrast, ARE freely editable via `PATCH` after
creation — per S12's "edits create controlled amendments," and per this sprint's scope decision,
"controlled" is satisfied by the existing `Auditable` trait's before/after audit trail, not by
building a separate formal amendment-approval workflow (that heavier mechanism, for changes that
affect *commercial value*, is exactly what Sprint 7's Change Orders are for — don't build a
lightweight duplicate of it here for non-commercial fields).

### Schema

`contracts` (project-scoped indirectly, same convention as `boq_items`/`pricing_rules`/
`proposal_versions`): id, project_id, proposal_version_id (FK to proposal_versions, unique —
enforces the 0..1 relationship), contract_no (string, auto-generated like `projects.code`'s
`PRJ-00001` pattern — use `CTR-00001`, with the same conflict-fallback approach), status (string;
`active` by default at creation — no draft state needed since creation IS signing; leave room for
future values like `completed`/`terminated` but don't build transitions for them this sprint),
contract_value (decimal(14,2), copied from the proposal's `grand_total` at creation, never
recalculated), signed_at (timestamp, set at creation), start_date/end_date (nullable dates,
editable), terms_json (jsonb, nullable — seed it from the proposal's `content_json` terms/
exclusions/timeline/payment_plan sections as a starting draft, but store it as the contract's own
independent copy so later proposal changes — there won't be any, the proposal is already
immutable by this point — or future contract edits don't entangle the two records). Wire
`Auditable`.

### API

- `POST /projects/{id}/contracts/from-proposal/{proposalId}` — validate the referenced proposal
  version belongs to this project and has `status == 'approved'` (else 409/422, your choice of
  code, document it), validate no contract already exists for it (409
  `CONTRACT_ALREADY_EXISTS`), then in a DB transaction create the contract (contract_value =
  proposal.grand_total, terms_json seeded from proposal.content_json, signed_at = now(),
  status = 'active').
- `GET /contracts/{id}` — full detail.
- `PATCH /contracts/{id}` — update start_date/end_date/terms_json only; reject any attempt to set
  contract_value or proposal_version_id (ignore silently or 422 if present in the payload — your
  call, document it).
- `GET /contracts/{id}/pdf` — internal-only PDF (no public contract-viewing surface exists in the
  PRD; the client already reviewed and approved the commercial terms via Sprint 4's proposal
  portal — a contract PDF here is for the office's/client's records, shared manually like the
  proposal link, not a new public route).

Permission: reuse whatever permission Sprint 4 settled on for proposals (`manage_boq`, per that
sprint's documented choice) unless you have a strong reason to diverge — keep the commercial
workflow's permission story consistent rather than fragmenting it further.

### UX

**S12 Contract** only (not S13 Payments — that's Sprint 6): contract metadata (contract_no,
status, value, dates), parties (project's client + organization), terms (editable textareas
mirroring the proposal editor's content-section pattern), a read-only reference back to the
source proposal version, a PDF download action. Wire it into the project's in-project nav as a
new enabled "Contract" tab (currently a disabled placeholder). If the project's approved proposal
hasn't been converted yet, show a "Convert to Contract" call-to-action (only enabled when an
approved proposal version exists and no contract exists yet) rather than an empty contract form.

Stop and checkpoint with the user after this sprint before starting Payments (Sprint 6).

## Sprint 6 scope (complete)

Payments: schedules, recording, receipts, receivables. This sprint introduces two things not yet
present anywhere in the codebase — a second money-moving public-adjacent-risk surface (payment
recording, per the NFR "idempotency keys for payment creation and other money-moving POSTs") and
the first real file upload (receipts) — plus it finally activates a permission that's been
sitting unused since Sprint 1.

### Scope boundary: what's explicitly OUT

`expenses`, `suppliers`, and `purchase_orders` are in the ERD (§2) and have a UX screen (S18
"Expenses / Suppliers"), but the PRD's own MVP delivery order (§6) does **not** assign them to
any of the 8 sprints — Sprint 6 is specifically "Schedules, payment recording, receipts,
receivables dashboard," which is all about money coming IN, not costs going out. Do not build
expenses/suppliers this sprint. Consequently `ProjectResource.financials.actual_cost` and
`.gross_profit` STAY at their Sprint 1/3 placeholder values (0 / null) — they need real expense
data that has no assigned sprint in this PRD. Only `.value` (Sprint 3), `.collected`, and
`.outstanding` (this sprint) get populated with real numbers. If the user wants expenses/
suppliers built, that's an explicit addition beyond the documented 8-sprint plan — flag it back
to the supervisor rather than quietly building it.

### Schema

1. **`payment_schedules`** — scoped indirectly, THREE hops this time: contract_id ->
   contracts.project_id -> projects.organization_id (one hop deeper than proposal_items' two-hop
   case in Sprint 4 — follow that model's documented pattern for how to note this in the
   docblock). Fields: id, contract_id (FK, cascadeOnDelete), name, sequence_no (integer),
   due_date (date), percentage (decimal, nullable), amount (decimal(14,2)), status (string,
   default `'pending'`). **Status semantics — a deliberate simplification**: only two stored
   values, `pending` and `paid` (flips to `paid` once cumulative recorded payments against this
   schedule reach its `amount`). "Overdue" and "upcoming" are NOT stored statuses — they're
   **computed at read time** as `pending AND due_date < today` / `pending AND due_date >= today`
   respectively. This avoids needing a scheduled job to flip status over time and keeps the
   stored source of truth simple; the UX's "filter overdue/upcoming/paid" is a presentation-layer
   filter over this computed value, not a third database state. Wire `Auditable`.
2. **`payments`** — per the ERD, directly `organization_id` + `project_id` scoped (unlike
   `payment_schedules` — this is the ERD's own choice, not a convention deviation, since payments
   are meant to be queryable project-wide even across multiple contracts/schedules). Fields: id,
   organization_id, project_id, payment_schedule_id (FK), amount (decimal(14,2)), payment_method
   (string), paid_at (timestamp), reference (string, nullable), receipt_url (string, nullable —
   see file upload below), notes (text, nullable). Use `BelongsToOrganization` directly. Wire
   `Auditable` (financial records are exactly what that trait exists for).
3. **Idempotency reuse**: `POST /payment-schedules/{id}/payments` is a money-moving POST — reuse
   Sprint 4's generic `idempotency_keys` table and its exact replay semantics (matching key
   replays the stored response verbatim; no/different key applies normal rules). Scope string
   e.g. `"payment_create:{payment_schedule_id}"`. This is an **authenticated internal** endpoint
   (staff record payments they received, e.g. bank transfer/cash — recall the locked decision:
   "payments recorded only, platform does NOT initiate collection"), so there's no OTP involved
   here, just the idempotency-key replay mechanism, reused verbatim from Sprint 4's pattern.

### File uploads (receipts) — new infrastructure this sprint

No file/document upload exists anywhere in the codebase yet (the ERD's `documents` table isn't
built either — that's implicitly bundled into whichever future work needs it, not explicitly
scheduled; Sprint 6 only needs enough file handling for payment receipts specifically, don't
build a general-purpose document-management feature). The NFR says "object storage with signed
URLs; virus scanning and size/type restrictions" — for this dev environment, implement against
Laravel's local filesystem disk (already configured, `FILESYSTEM_DISK=local`) behind the
`Storage` facade so a later swap to S3-compatible object storage is a config change, not a code
rewrite. Concretely:
- `POST /payment-schedules/{id}/payments` accepts an optional multipart `receipt` file field
  (image or PDF, reasonable size cap e.g. 10MB — validate mime type, don't just trust the
  extension).
- Store it via `Storage::disk('local')->putFile(...)` under a path scoped by organization/project
  (e.g. `receipts/{organization_id}/{payment_id}.{ext}`), record the storage path (not a public
  URL) in `payments.receipt_url`.
- Add `GET /payments/{id}/receipt` (authenticated, tenant-checked) that streams/redirects to the
  file — this is the "signed URL" in spirit (access-controlled via the app, not a truly public
  S3 pre-signed URL, since there's no S3 in this dev environment) — don't expose a raw public
  `/storage/...` path directly.
- Skip virus scanning for MVP dev (no ClamAV or equivalent available in this environment) — note
  this explicitly as a known gap for production hardening, don't silently skip it without saying
  so.

### Permission: activate `view_financials`

`Permissions::VIEW_FINANCIALS` has existed since Sprint 1's `RoleSeeder` but has never gated
anything — no endpoint has used it yet (BOQ/pricing/proposal/contract reads all only required
active membership, which in hindsight is looser than the DoD's "role permissions prevent...
site users from seeing internal cost/profit" really wants, but retrofitting that onto earlier
sprints is out of scope here — don't touch those). Sprint 6 is where this matters most: payment
schedules, payment records, and `GET /projects/{id}/financials` are exactly the profit-adjacent
data the locked decisions care about. Gate **reads** of payment_schedules/payments/financials
behind `view_financials`, not just active membership. Gate **mutations** (creating schedules,
recording payments) behind `manage_boq`, consistent with the rest of the commercial workflow's
permission convention — a designer can still record a payment, but a Site Staff role (which,
per the seeded `RoleSeeder`, has neither permission) correctly can't even see the numbers.

### API

- `POST /contracts/{id}/payment-schedules` — create an installment. Accept either `amount`
  directly, or `percentage` (in which case compute `amount = contract.contract_value *
  percentage/100` via bcmath — never float). `sequence_no` and `due_date` required.
- `GET /contracts/{id}/payment-schedules` — list, ordered by `sequence_no`.
- `POST /payment-schedules/{id}/payments` — record a payment against a schedule (see idempotency
  + file upload above). Support partial payments (amount less than the schedule's remaining
  balance) — track cumulative payments, only flip the schedule to `'paid'` once the cumulative
  total reaches the schedule's `amount`. In a DB transaction: create the payment row, update the
  schedule's status if now fully paid.
- `GET /payment-schedules/{id}/payments` — list payments recorded against one schedule (not in
  the PRD's route table but a reasonable, consistent addition — needed for a receipts history
  view; keep it RESTful like prior sprints' similar additions).
- `GET /payments/{id}/receipt` — stream the uploaded receipt file (auth + tenant + `view_financials`).
- `GET /projects/{id}/financials` — `{value, collected, outstanding, actual_cost: 0, gross_profit:
  null}` (the last two stay placeholders per the scope-boundary section above). `collected` = sum
  of all `payments.amount` for the project. `outstanding` = the project's contract's
  `contract_value` minus `collected` (0 if no contract yet). Gate behind `view_financials`.
- Also update `ProjectResource.financials` itself (the one embedded in `GET /projects/{id}`,
  not just the standalone financials endpoint) to pull real `collected`/`outstanding` — it's
  been returning 0 placeholders since Sprint 1.

### UX

**S13 Payments** (project tab, newly enabled — was a disabled placeholder): a receivables table
listing payment schedules (name, due date, amount, status badge — computed overdue/upcoming/paid
as described above) with filter tabs/buttons for those three states, a "Record Payment" action
per schedule opening a form (amount, payment method, paid_at, reference, notes, receipt file
upload), and a payment history/receipts list per schedule. If the project has no contract yet,
show an empty state pointing at the Contract tab (mirroring Sprint 5's own empty-state pattern
for "no approved proposal yet"). Also update the **S06 Project Overview** financials block
(currently showing 0 for Collected/Outstanding since Sprint 1) to reflect the real
`GET /projects/{id}` `financials.collected`/`.outstanding` values now that they're populated.

Stop and checkpoint with the user before starting Change Orders (Sprint 7).

## Sprint 7 scope (complete)

Change Orders: the controlled-amendment mechanism Sprint 5 explicitly deferred ("changes that
affect commercial value... is exactly what Sprint 7's Change Orders are for"). This sprint has
four distinct lifecycle verbs (per the MVP delivery order's own label: "Create/send/approve/
apply") and is the second real user of Sprint 4's generic OTP+idempotency+signed-link mechanism
— it must be reused, not reimplemented.

### Lifecycle (four verbs, four distinct steps — don't collapse any of them)

`draft` → `sent` → `approved` | `rejected` → `applied` (only reachable from `approved`).

1. **Create** (`draft`): staff specifies a list of BOQ deltas (see `change_order_items` below)
   and a reason + `timeline_delta_days`. `price_delta` is COMPUTED from the items' `line_delta`
   sum, not manually entered (same "compute from lines, don't trust a manual total" principle as
   proposals' `grand_total`). Freely editable while draft (add/remove/edit items), same
   convention as proposal drafts.
2. **Send**: locks the draft (same immutability-at-sent boundary as proposals — Sprint 4's
   pattern, don't invent a different boundary here), issues a signed link (`SignedLinkService`,
   purpose e.g. `"change_order_approval"`) + a fresh OTP challenge (`OtpChallengeService`,
   REUSE VERBATIM — don't reimplement hashing/expiry/attempt-cap logic), sets `sent_at`.
3. **Approve** (client, public, OTP-gated): `POST /public/change-orders/{token}/approve` —
   mirror Sprint 4's proposal-approval endpoint exactly: same OTP verification, same
   Idempotency-Key replay semantics, same `409 CHANGE_ORDER_ALREADY_APPROVED`-style conflict
   handling for a retry without a matching key. Creates an `approvals` row (`entity_type =
   'change_order'` — this is exactly why that table's `entity_type`/`entity_id` was built
   generic back in Sprint 4, not proposal-specific). Sets `status = 'approved'`, `approved_at`.
   **Approving does NOT yet touch the BOQ or the contract** — that's the separate `apply` step
   (see below for why these are kept distinct).
4. **Reject** (client, public, NO OTP): `POST /public/change-orders/{token}/reject` — mirrors
   proposals' `request-changes` (lower stakes than a binding commercial approval, no OTP
   needed). Sets `status = 'rejected'`, creates an `approvals` row (`status = 'rejected'`). A
   rejected change order is terminal — staff creates a new one if they want to try again (same
   "new draft, don't reopen an old one" principle as proposal versioning).
5. **Apply** (staff, internal, authenticated — NOT in the PRD's route table but required by the
   sprint's own "...apply" label; add `POST /change-orders/{id}/apply`, only valid from
   `approved`): this is the ONE controlled, audited exception to Sprint 5's contract immutability
   rule (`contract_value` is otherwise permanently locked). Applying, in a DB transaction:
   - Walks `change_order_items` and mutates the live `boq_items` accordingly (see actions below).
   - Updates `contracts.contract_value += price_delta` directly (NOT via a full BOQ/pricing
     recalculation — `price_delta` is the authoritative, already-computed delta; recomputing from
     scratch could drift from what the client actually approved). This is why it bypasses
     `UpdateContractRequest`'s prohibition on `contract_value` — that guard is for the generic
     PATCH endpoint; `apply` is a distinct, purpose-built internal service method, not a
     backdoor through the same route.
   - Optionally extends `contracts.end_date` by `timeline_delta_days` if `end_date` is set (skip
     if null — don't invent a start date to extend from).
   - Sets `status = 'applied'` and a new `applied_at` timestamp (not in the ERD's literal column
     list — add it, same pattern as adding `priced_at`/`archived_at` in earlier sprints when the
     ERD didn't enumerate every timestamp a real workflow needs).
   Why separate `approve` from `apply` instead of applying immediately on client approval: gives
   staff a deliberate checkpoint to verify before the BOQ/contract actually change — the client
   approving doesn't mean the office has necessarily scheduled the work yet.

### Schema

1. **`change_orders`** — project-scoped indirectly (same convention as `boq_items`/
   `pricing_rules`/`proposal_versions`). Fields: id, project_id, number (string, auto-generated
   like `contracts.contract_no`'s `CTR-00001` pattern — use `CO-00001`), status (string, default
   `'draft'`), reason (text), price_delta (decimal(14,2), computed from items — see above),
   timeline_delta_days (integer, nullable, can be negative for a schedule pull-forward),
   requested_by (nullable FK to users), approved_at (nullable timestamp), applied_at (nullable
   timestamp — added beyond the ERD's literal list, per above). Skip a literal `approved_by`
   column — per the established pattern (`proposal_versions.approved_at` has no paired user_id
   since the client isn't a `users` row), the `approvals` table is the detailed record of WHO
   approved; don't duplicate that here. Wire `Auditable`.
2. **`change_order_items`** — belongs to `change_orders` (two-hop indirect scoping, same pattern
   as Sprint 4's `proposal_items` — check that model's docblock for the exact style). Fields: id,
   change_order_id (FK, cascadeOnDelete), action (string: `'add'` | `'remove'` | `'modify'`),
   boq_item_id (nullable FK to boq_items, nullOnDelete — null for `'add'` since there's no
   existing item yet; required for `'remove'`/`'modify'`), description, quantity, unit,
   old_unit_price (nullable — null for `'add'`), new_unit_price (nullable — null for
   `'remove'`), line_delta (decimal(12,2), computed per action, see below). **Scope
   simplification**: `'modify'` changes `new_unit_price` only, not quantity — if a real
   quantity change is needed, model it as a `'remove'` + `'add'` pair rather than extending
   `'modify'` to handle both dimensions; keeps the line-delta math unambiguous for MVP.
   `line_delta` formulas: `add` → `quantity * new_unit_price` (positive); `remove` → `-(quantity
   * old_unit_price)` (negative); `modify` → `quantity * (new_unit_price - old_unit_price)`
   (sign follows whether the price went up or down).

### API

- `POST /projects/{id}/change-orders` — create draft (reason, timeline_delta_days, items[]).
  Validate any referenced `boq_item_id` belongs to this project. Compute `price_delta` from
  items server-side.
- `GET /projects/{id}/change-orders` — list (number, status, price_delta, timeline_delta_days,
  sent_at, approved_at, applied_at).
- `GET /change-orders/{id}` — full detail including items.
- `PATCH /change-orders/{id}` — edit reason/timeline_delta_days/items, only while `draft`
  (recompute `price_delta`), 409 otherwise — mirror proposals' `PROPOSAL_NOT_EDITABLE` pattern
  with an analogous code.
- `POST /change-orders/{id}/send` — only from `draft`; mirror `ProposalVersionController::send()`
  exactly (signed link + OTP issuance, response includes the plaintext OTP once).
- `POST /change-orders/{id}/apply` — only from `approved`; see the Apply step above.
- `GET /public/change-orders/{token}` — public, token-authenticated, rate-limited. Client-shaped
  view: change order number/reason/timeline_delta_days/price_delta (this IS shown to the client —
  unlike BOQ cost fields, a price delta is the whole point of what they're approving) and the
  item list in client-shaped form (description/quantity/unit, old/new price — no
  `boq_item_id`/internal linkage).
- `POST /public/change-orders/{token}/approve` — body `{name, comment?, otp}`, optional
  `Idempotency-Key` header — copy Sprint 4's `PublicProposalController::approve()` logic
  structurally (same OTP service, same idempotency reconciliation), adapted for
  `change_orders`/entity_type `'change_order'`.
- `POST /public/change-orders/{token}/reject` — body `{name, comment}`, no OTP — mirrors
  `request-changes`.

**Permission**: reuse `manage_boq` for all mutations (create/update/send/apply), consistent with
the established commercial-workflow convention. Reads (list/detail) need only active
membership — do NOT gate behind `view_financials`; a change order's `price_delta` is a *pricing*
concept (same tier as BOQ/pricing-rule/proposal/contract data, all of which only ever required
active membership) rather than a *collected-money* concept (which is what `view_financials`
was introduced in Sprint 6 specifically to protect). Keep that distinction intentional, don't
default to the stricter gate just because it's newer.

### UX

**S14 Change Orders** (project tab, new — enable it, currently a disabled placeholder): list of
change orders with status badges, an editor for a draft (reason, timeline delta, an
additions/removals/modifications table against the current BOQ — let staff pick an existing BOQ
item to remove or modify, or add a free-form new line), a running price-delta total, a Send
action surfacing the copyable link+OTP exactly like the Proposal Editor's pattern, and an Apply
action visible only once a change order is `approved`. A **public Change Order approval page**
(new unauthenticated route, e.g. `/p/change-orders/[token]`, mirroring `/p/proposals/[token]`'s
architecture and isolation from the authenticated app exactly) showing the change's reason, items,
price delta, timeline impact, and Approve (name+OTP)/Reject (name+comment) actions.

Stop and checkpoint with the user after this sprint before starting Polish (Sprint 8) — the
final sprint of the MVP delivery order.

## Sprint 8 scope (current) — final sprint

Polish: "Reports, permissions hardening, notifications, QA, analytics, deployment" (PRD §6's own
label). Read this whole section before writing code — it draws a scope boundary that matters,
resolves a real security gap found by inspection (not by an agent's smoke test this time, by the
supervisor directly reading the existing read-permission code), and reuses several established
patterns (dormant-permission activation, CSV export, bell/dropdown notification UI) rather than
inventing new ones.

### Scope boundary: what's explicitly OUT (again — this is the last chance to say it)

The ERD (§2) and UX spec (§4) describe `tasks`, `site_reports`, `snags`, `handover_records`,
`documents`, `expenses`, `suppliers`, `purchase_orders`, and screens S16–S20 (Tasks/Site, Site
Report, Expenses/Suppliers, Snagging, Handover). **None of these are in the PRD's 8-sprint MVP
delivery order** (§6) — the API spec itself labels that whole group "Execution — Phase 2." Sprint
8 is Polish on the 7 sprints already built, not a place to sneak in Phase 2 scope. Do not build
any of the above. This also means `ProjectResource.financials.actual_cost`/`.gross_profit` STAY
at their placeholder values (0/null) — real actual-cost tracking needs `expenses`, which isn't
being built. Reports (below) work entirely from data already in the system: BOQ-estimated
direct cost, contract value, and recorded payments — never real expenses.

### Permissions hardening (a real gap, found by direct inspection)

Every BOQ and pricing-rule GET endpoint since Sprint 2/3 has required only "active membership,"
not `Permissions::MANAGE_BOQ` — but `BoqItemResource` (and the BOQ tree/export responses) include
`material_unit_cost`/`labor_unit_cost`/`other_unit_cost`, and pricing-rule reads expose markup
percentages. The seeded `Site Staff` role has every permission set to `false` (see
`RoleSeeder`), yet because reads never checked a permission at all, a Site Staff member — an
active org member — can currently call `GET /projects/{id}/boq` (or its CSV export) and see
every internal cost figure. This directly contradicts the Definition of Done: "Role permissions
prevent clients and site users from seeing internal cost/profit." Fix: change the following GET
endpoints to require `Permissions::MANAGE_BOQ` (matching their sibling write endpoints, which
already require it) instead of just active membership:
- `GET /projects/{id}/boq`, `GET /projects/{id}/boq/export`
- `GET /projects/{id}/pricing/rules`, `GET /projects/{id}/pricing/breakdown`
- `GET /projects/{id}/rooms` — arguably lower stakes (no cost data), but rooms only exist to
  organize BOQ line items (per Sprint 2's own routing comment) — harden it too for consistency
  rather than leaving one BOQ-adjacent read endpoint on the looser rule.

Do NOT touch proposal/contract/change-order read endpoints — those response shapes were already
built cost-free from Sprint 4 onward (proposal_items, change_order public/internal views, etc.
never had cost fields to begin with), so "active membership" reads there were never a leak. Only
touch the endpoints named above. After hardening, re-verify (or have QA re-verify) that Designer/
Admin/Owner workflows are unaffected (they all have `MANAGE_BOQ`) and that Site Staff is now
correctly blocked (403) from the endpoints above while still able to read clients/projects/
proposals/contracts/payments-financials-summary as before (Site Staff's existing access to
non-cost data is unchanged).

### Notifications (activates the ERD's unused `notifications` table)

Schema: `notifications` (id, organization_id, user_id, channel, type, payload_json, sent_at,
read_at) — directly organization+user scoped (`BelongsToOrganization`), per the ERD. `channel` is
always `'in_app'` for this MVP (no email/SMS/WhatsApp sending infrastructure exists — the column
supports future channels, don't build them now). `type` is a short string identifying the event
(e.g. `'proposal_approved'`, `'contract_created'`, `'payment_received'`,
`'change_order_approved'`, `'change_order_rejected'`). `payload_json` carries whatever the
frontend needs to render/link the notification (project id/name, entity id, a human summary).

**Who gets notified**: the project's `responsible_user_id` if set; otherwise every organization
member holding `MANAGE_BOQ` (a reasonable fallback so a notification is never silently dropped).
Wire notification creation into the existing controllers/services for these events (all from
already-built features, don't invent new trigger points):
- Proposal approved (`PublicProposalController::approve()`)
- Proposal changes requested (`PublicProposalController::requestChanges()`)
- Contract created (`ContractService`/`ContractController::fromProposal()`)
- Payment received (`PaymentRecordingService`)
- Change order approved (`PublicChangeOrderController::approve()`)
- Change order rejected (`PublicChangeOrderController::reject()`)

API: `GET /notifications` (current user's own, paginated, newest first, auth:sanctum + tenant —
no extra permission needed, a user only ever sees their own), `POST
/notifications/{id}/read` (mark one read), `POST /notifications/read-all`.

UX: a bell icon with an unread-count badge in the app shell's top nav (visible on every
authenticated page, not project-scoped), opening a dropdown/panel listing recent notifications
with a way to mark them read and a "read-all" action. Keep it simple — no push notifications, no
polling requirement beyond a reasonable refresh-on-navigation.

### Reports (S21)

Two endpoints, both gated behind `Permissions::VIEW_FINANCIALS` (this is exactly the
profit-adjacent data that permission exists to protect):
- `GET /reports/summary` — organization-wide KPIs: `total_revenue` (sum of all `payments.amount`
  org-wide), `total_receivables` (sum of `contract_value - collected` across every project with a
  contract), `estimated_margin` (sum of `grand_total - direct_cost_total` across every priced
  project — label this "estimated," not "profit," in both the API shape and the UI, since it's
  derived from BOQ pricing, not real expenses), `total_change_order_value` (sum of `price_delta`
  across every `applied` change order org-wide), `project_count`, `active_project_count`.
- `GET /reports/projects` — per-project breakdown table: project name/code/status,
  `contract_value`, `collected`, `outstanding`, `estimated_margin`, `change_order_value` (sum of
  that project's applied change orders' `price_delta`), and `budget_variance` (`contract_value -`
  the originating proposal's frozen `grand_total`, via `contract.proposalVersion` — this should
  equal the project's `change_order_value` exactly by construction, since that's the only thing
  that moves `contract_value` after creation; a good internal consistency check for QA to verify,
  not just a display figure to trust blindly). Support CSV export via a `format=csv` query
  param or a separate `GET /reports/projects/export` route — your call, mirror whichever
  precedent (BOQ CSV export from Sprint 2) reads more naturally. Skip PDF export — CSV covers the
  accounting/reconciliation use case (same reasoning Sprint 2 used to skip Excel), and this is
  the last sprint, keep it scoped.

UX: a new "Reports" page — the top-level nav item has been a disabled placeholder since Sprint 1,
enable it now. KPI cards (from `/reports/summary`) + a sortable per-project table (from
`/reports/projects`) + a CSV export button. Not project-scoped — lives at the organization level
alongside Dashboard/Clients/Projects.

### Settings (S22, scoped down)

Full S22 (branding, numbering templates, markup templates, proposal templates, notification
preferences) is more than this sprint should absorb — most of those "templates" concepts don't
have dedicated schema and inventing one now, in the last sprint, isn't justified by any DoD
bullet. Scope down to what's genuinely useful and low-risk:
1. **Organization profile**: `PATCH /organizations/{id}` for the branding fields that already
   exist as columns since Sprint 1 (`name`, `legal_name`, `logo_url`, `phone`, `email`,
   `currency`, `timezone`) — this endpoint doesn't exist yet, add it. Gate behind
   `Permissions::MANAGE_ORGANIZATION` (seeded since Sprint 1, never used by any endpoint until
   now — same "activate a dormant permission" pattern as Sprint 6's `VIEW_FINANCIALS`).
2. **Members & roles**: a UI for the organization-member invite/role-change APIs that have
   existed since Sprint 1 (`POST /organizations/{id}/members/invite`, `PATCH
   /organizations/{id}/members/{member}`) but never got a frontend — add `GET
   /organizations/{id}/members` (list, doesn't exist yet either) plus the UI to invite a member
   and change their role.

UX: a "Settings" page — top-level nav item, disabled placeholder since Sprint 1, enable it now.
An organization profile edit form + a members list with an "Invite" action and per-member role
dropdown.

### Deployment readiness (documentation, not an actual live deploy)

Write `docs/DEPLOYMENT.md` covering what's needed to run this in a real environment: required
environment variables and what changes from dev (`APP_ENV=production`, `APP_DEBUG=false`, real
Postgres/Redis credentials, swapping the receipt-storage `Storage::disk('local')` for a real
S3-compatible disk — the seam for this was explicitly built in Sprint 6 to make this a config
change, not a code change), running migrations, building the frontend for production, and a
queue-worker note (nothing currently queues background jobs, but note it for future email/PDF
generation work). Do NOT attempt to actually provision or deploy to any live infrastructure —
that requires hosting/credential decisions only the user can make; this is a readiness document,
not an action.

### QA scope for this sprint

Beyond testing the new endpoints above, specifically verify the permissions-hardening change
doesn't regress Designer/Admin/Owner access (they all have `MANAGE_BOQ`) while confirming Site
Staff is now blocked from the hardened endpoints. Also do a final Definition-of-Done pass across
*all* 8 sprints (not just this one) — the project is complete after this sprint, so this is the
last checkpoint to catch anything that slipped through an earlier sprint's review.

This is the final sprint. After it, do a full-project summary rather than a "start Sprint 9"
checkpoint — there is no Sprint 9 in the PRD's delivery order.
