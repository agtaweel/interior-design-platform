# Agent Orchestration Playbook

## Roles

**Supervisor** — the main Claude Code session (no separate agent file; it *is* the orchestrator).
Responsibilities:

1. Break each sprint (per `docs/PROJECT_CONTEXT.md` §"MVP delivery order") into concrete tasks
   using TaskCreate/TaskUpdate.
2. Dispatch tasks to the right subagent via the Agent tool, in dependency order (schema before
   API, API before frontend, implementation before tests).
3. Review each subagent's diff before moving on — trust but verify, per standing engineering
   practice: an agent's summary describes intent, not necessarily what happened.
4. Resolve cross-agent conflicts (e.g. db-architect and backend-api-engineer disagreeing on a
   column name) directly rather than letting agents argue across turns.
5. Run the project-level checkpoint (tests pass, Definition-of-Done bullets satisfied) before
   declaring a sprint done and pausing for user review.

**Subagents** — defined in `.claude/agents/`:

| Agent | Owns | Reads before writing |
|---|---|---|
| `db-architect` | migrations, models, indexes | PROJECT_CONTEXT.md §ERD |
| `auth-security-engineer` | auth, RBAC, tenancy scope, public links, audit log wiring | PROJECT_CONTEXT.md §locked decisions, §NFRs |
| `backend-api-engineer` | controllers, services, validation, error envelope | PROJECT_CONTEXT.md §REST API, current migrations |
| `frontend-engineer` | Next.js screens, i18n/RTL | PROJECT_CONTEXT.md §UX summary |
| `qa-test-engineer` | tests, Definition-of-Done verification | PROJECT_CONTEXT.md §Definition of Done |

## Per-sprint sequence

1. Supervisor creates/updates tasks scoped to the sprint.
2. `db-architect` runs first if the sprint introduces new tables/columns.
3. `auth-security-engineer` runs next only if the sprint touches auth/RBAC/tenancy/audit (heavy
   in Sprint 1, lighter later).
4. `backend-api-engineer` implements endpoints against the now-stable schema.
5. `frontend-engineer` builds screens against the (documented, possibly stubbed) API.
6. `qa-test-engineer` writes/runs tests and reports Definition-of-Done status.
7. Supervisor reviews the combined diff, runs a final check, and checkpoints with the user.

## Parallelization notes

- `db-architect` and `auth-security-engineer`'s early scaffolding (organizations, users, roles)
  are on the critical path — nothing else can start until this lands.
- Once schema is stable, `backend-api-engineer` and `frontend-engineer` can often work in
  parallel against the documented API contract (frontend stubs the shape if backend isn't done
  yet).
- `qa-test-engineer` should run last per sprint, not in parallel, since it needs real
  implementations to test against meaningfully.

## Current status

Sprints 1–6 (Foundation, BOQ, Pricing, Proposals, Approval+Contract, Payments) are **complete**.
268 passing backend tests. Stopped here for user checkpoint. Sprints 7–8 (Change Orders, Polish)
are not started.

Sprint 6 delivered: payment schedules (percentage-of-contract or fixed amount, computed via
bcmath) and payment recording reusing Sprint 4's idempotency-key mechanism verbatim, receipt file
uploads (local disk behind the Storage facade, streamed back through an access-controlled route
rather than a raw public path — a stand-in for a real object-storage signed URL), and the S13
Payments tab with overdue/upcoming/paid filters and live remaining-balance tracking for partial
payments. This sprint activated the `view_financials` permission that had sat unused since
Sprint 1 — it's the first sprint where reads (not just writes) are permission-gated, since
payment/financial data is exactly the profit-adjacent information the locked decisions care
about. `actual_cost`/`gross_profit` remain deferred placeholders by design — expenses/suppliers
aren't assigned to any of the PRD's 8 MVP sprints.

**Process notes from this sprint**: I made a mistake mid-sprint — a `git checkout` intended to
revert my own one-line test edit instead reverted an entire file, wiping out a subagent's route
registrations (everything else, including the controllers/services, was untracked or otherwise
safe and unaffected). Caught immediately via route:list showing 0 payment routes, fixed by
re-adding the routes directly. Separately, a real recurring gotcha across several sprints
(agents reporting Docker "staleness" requiring a restart) was finally confirmed and root-caused:
editor-style atomic-save writes to existing `backend/` files don't reliably propagate into the
`idp-app` container's bind-mount view, while plain shell appends do. Documented in
`docs/PROJECT_CONTEXT.md`'s "Local dev environment" section — the fix is `docker compose restart
app` after editing existing backend files, before trusting `artisan`/curl output.

Sprint 5 delivered: contract conversion from an approved proposal (creation = signing, per the
locked "OTP not e-signature" decision — no separate contract-signing ceremony), a DB-enforced
0..1 proposal-to-contract relationship, contract_value/proposal_version_id permanently locked
while start_date/end_date/terms_json stay editable (with Auditable's trail satisfying "controlled
amendments" rather than a separate approval workflow — that heavier mechanism is Sprint 7's
Change Orders), and the S12 Contract tab. A real API gap was found and fixed mid-sprint: there
was no way to look up an existing contract for a project (only a failing conversion attempt could
reveal one, and even then without an id) — added `GET /projects/{id}/contracts` and included
`contract_id` in the `409 CONTRACT_ALREADY_EXISTS` error, then removed a fragile localStorage
workaround the frontend had used before that fix landed.

Sprint 4 delivered: proposal versioning with immutable snapshots (locked at 'sent', not just
'approved'), a generic OTP + idempotency-key mechanism (reusable by Sprint 7's change orders)
built on Sprint 1's dormant `SignedLinkService`, the full public/internal proposal API, PDF
generation via barryvdh/laravel-dompdf, the internal S09 Proposal Editor + S10 Version History,
and — the first unauthenticated surface in the app — the S11 public Client Proposal Portal at
`/p/proposals/[token]`, verified live at mobile viewport width with zero cost/margin leakage in
the JSON response, the PDF, or the DOM. The trickiest piece (idempotency-key replay vs.
already-approved 409) was verified precisely in both directions by both the implementer and QA.
One hardening fix applied post-QA: `OtpChallengeService::verify()` now fails closed instead of
throwing a 500 if `code_hash` is ever malformed (defense-in-depth on a public endpoint; not
reachable in normal operation since the hash is always written by the same code path).

Sprint 2 delivered: rooms/boq_categories/boq_items schema, org-level BOQ templates (clone-not-
reference semantics), full CRUD + nested tree/totals endpoint, CSV import/export, apply-template
endpoint, S07 BOQ Builder UI (spreadsheet-style grid, room/category filters, template apply,
CSV import/export). A mid-sprint infrastructure bug was found and fixed: `docker compose exec
app php artisan test` was silently running `migrate:fresh` against the real dev Postgres database
instead of an isolated sqlite one, because `docker-compose.yml`'s `env_file` leaks real OS env
vars that PHPUnit's `<env>` tags don't override without `force="true"`. Fixed in
`backend/phpunit.xml` + `backend/tests/bootstrap.php` — verified holding through Sprint 3. A gap
(no way to create a room from the UI, only via CSV import) was found during frontend testing and
closed with a `POST /projects/{id}/rooms` endpoint + UI form.

Sprint 3 delivered: `pricing_rules` (markup/fee/discount layers with three base_selector modes —
cost-plus, fixed-anchor-on-line-item-total, and cascading-on-running-subtotal) applied on top of
Sprint 2's per-item pricing, a recalculation engine with persisted cache columns on `projects`,
an internal line-by-line breakdown endpoint, a client-facing seam (`ProjectPricingClientResource`,
grand_total only, not yet wired to a route — Sprint 4 will use it), and the S08 Pricing Panel UI
with a genuine internal/client view toggle (client mode never puts cost data in the DOM). A mass-
assignment bug (cache-column writes silently dropped, response looked correct but DB write
no-op'd) was caught and fixed during implementation. `financials.value` on the project overview
now reflects real computed pricing instead of a placeholder zero.

Known minor cleanup item (non-blocking): a handful of throwaway orgs/clients/projects from
agents' own manual verification steps are still in the dev Postgres database (delete attempts
are blocked by the environment's action classifier for both subagents and the supervisor) —
harmless local dev clutter, not a functional issue.
