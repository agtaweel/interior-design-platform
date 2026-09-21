# Interior Design & Finishing Management Platform

Multi-tenant SaaS for interior design/finishing companies. Full context, locked product
decisions, ERD/API/UX summaries and sprint plan live in [docs/PROJECT_CONTEXT.md](docs/PROJECT_CONTEXT.md) —
read that before making any architectural decision.

## Stack

- `backend/` — Laravel API, PostgreSQL, Redis, Sanctum auth.
- `frontend/` — Next.js + TypeScript, i18n (ar/en, RTL), Tailwind.

## Agent team

This project is built by a supervisor (the orchestrating Claude Code session) directing five
specialized subagents defined in `.claude/agents/`:

- **db-architect** — migrations, Eloquent models, indexes, financial-integrity constraints.
- **auth-security-engineer** — Sanctum auth, RBAC, tenant isolation, signed/OTP public links, audit logging.
- **backend-api-engineer** — REST API controllers/services per PRD §3.
- **frontend-engineer** — Next.js screens per PRD §4.
- **qa-test-engineer** — feature/unit/authorization tests, Definition-of-Done verification.

Typical sprint order within each sprint: db-architect → auth-security-engineer (if
auth/RBAC-relevant) → backend-api-engineer → frontend-engineer → qa-test-engineer. See
`docs/AGENT_ORCHESTRATION.md` for the full playbook.

## Delivery plan

8 sprints (PRD §6): Foundation → BOQ → Pricing → Proposals → Approval+Contract → Payments →
Change Orders → Polish. We are building **Sprint 1 (Foundation)** first, then checkpointing with
the user before continuing.

## Non-negotiables (see PROJECT_CONTEXT.md for full detail)

- Every tenant table scoped by `organization_id`; never trust client-supplied org context.
- Money fields are `decimal`/`numeric`, never float.
- DB transactions around proposal approval, contract conversion, payment recording, change-order
  application.
- Approved commercial documents (proposal versions, contracts) are immutable snapshots.
- Internal cost/margin/supplier data never appears in client-facing or public API responses.
- Idempotency keys required on money-moving POSTs.
