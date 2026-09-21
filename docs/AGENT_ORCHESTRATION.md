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

See TaskList in the active session for live sprint progress. As of project bootstrap: Sprint 1
(Foundation) is in progress; Sprints 2–8 are not started pending user checkpoint after Sprint 1.
