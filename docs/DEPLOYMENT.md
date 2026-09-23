# Deployment Readiness

This is a readiness checklist and reference for running the Interior Design & Finishing
Management Platform in a real (non-local) environment. It documents what needs to change from
the local dev setup (`docker-compose.yml`, `backend/.env`) and why — it does not perform an
actual deployment, since that requires hosting/credential decisions only you can make (which
cloud provider, which domain, which object storage account, etc.).

## What's already production-shaped

A few things were deliberately built with a production swap in mind, so they're config changes,
not code changes:

- **Receipt storage** (`app/Services/Payments/PaymentRecordingService.php` and
  `app/Http/Controllers/Api/PaymentController::receipt()`) uses Laravel's `Storage` facade
  against the `local` disk. Swapping to S3-compatible object storage is a `config/filesystems.php`
  + `.env` change (`FILESYSTEM_DISK=s3` + `AWS_*` variables, already present as unset stubs in
  `.env.example`) — no application code touches the disk name directly.
- **PDF generation** (proposals, contracts) uses `barryvdh/laravel-dompdf`, a pure-PHP renderer
  with no headless-browser/system dependency — it runs the same way in any PHP-capable container.
- **Multi-tenancy, RBAC, audit logging** are all application-layer (no per-environment config
  needed beyond the database itself).

## Environment variables — what changes for production

Copy `backend/.env.example` to `backend/.env` on the target environment and set:

| Variable | Local dev value | Production guidance |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` — leaking stack traces in API error responses is a real information-disclosure risk |
| `APP_KEY` | (generated) | Generate a fresh one per environment: `php artisan key:generate` — never reuse the dev key |
| `APP_URL` | `http://localhost:8000` | The real public API URL (HTTPS) |
| `FRONTEND_URL` | `http://localhost:3000` | The real public frontend URL — used to build proposal/change-order public links (`SignedLinkService`), so this must be correct or shared links will point at the wrong host |
| `DB_*` | local Postgres container | Real managed Postgres credentials; keep `DB_CONNECTION=pgsql` (the only driver this codebase targets — see `docs/PROJECT_CONTEXT.md`'s Stack section) |
| `REDIS_*` | local Redis container | Real managed Redis credentials |
| `SESSION_DRIVER` / `CACHE_STORE` | `database` | Fine to leave as `database`, or switch to `redis` for lower DB load — no code depends on which |
| `QUEUE_CONNECTION` | `database` | No background jobs are dispatched anywhere in this MVP (see "Queue worker" note below) — `database` is fine as-is; only matters once something starts queueing |
| `FILESYSTEM_DISK` | `local` | `s3` (or another Laravel-supported driver) once real receipt uploads need to survive container restarts/redeploys — local disk storage is ephemeral on most container hosting |
| `AWS_*` | empty | Real object storage credentials/bucket, only if `FILESYSTEM_DISK=s3` |
| `MAIL_MAILER` | `log` | No feature in this MVP actually sends email (see locked decisions: no WhatsApp/email integration for MVP) — leave as `log` unless/until that changes, or point it at a real SMTP/API provider when it does |
| `BCRYPT_ROUNDS` | `12` | Fine as-is |

## Database

```
php artisan migrate --force
```

Do **not** run `migrate:fresh` against a production database — it drops every table. `--force` is
required in production because Laravel blocks destructive-looking migration commands outside
`local`/`testing` environments by default; it does not make the operation itself destructive, it
just applies pending migrations.

Seed the global template roles once, on first deploy only:

```
php artisan db:seed --class=RoleSeeder
```

(It's idempotent — `updateOrCreate` — safe to re-run if unsure whether it already ran.)

## Backend runtime

Any standard PHP 8.4 + Postgres + Redis hosting works — this codebase has no dependency on a
specific PaaS. The dev `Dockerfile` (`backend/Dockerfile`) is a reasonable starting point for a
production image; for a real deploy, additionally:

- Run `composer install --no-dev --optimize-autoloader` (dev's `composer install` includes
  test/dev dependencies unnecessarily).
- Run `php artisan config:cache route:cache view:cache` after deploying new code — dev
  intentionally does NOT cache these (so edits take effect without a rebuild), but production
  should, for both performance and to avoid the `.env`-parsing overhead on every request.
- Serve via a real web server (nginx/Apache + php-fpm) rather than `php artisan serve` — the
  built-in server is explicitly documented by Laravel as unsuitable for production.

## Queue worker (not currently needed, noted for future work)

Nothing in the current codebase dispatches a queued job — PDF generation, notification creation,
and audit logging all happen synchronously within the request that triggers them. This was a
deliberate simplicity choice for the MVP, not an oversight. If a future feature needs background
processing (e.g. real email/WhatsApp sending, or moving PDF generation off the request path for
latency), a `php artisan queue:work` process (or a serverless equivalent) will need to run
continuously — budget for that as a second long-running process alongside the web server, not a
one-off command.

## Frontend

```
cd frontend
npm ci
npm run build
npm run start   # or serve the .next output via your hosting platform's Next.js integration
```

Set `NEXT_PUBLIC_API_BASE_URL` (check `frontend/src/lib/api/client.ts` and
`frontend/src/lib/api/publicClient.ts` for the exact env var name used) to the real backend API
URL before building — it's baked in at build time for the public (unauthenticated) client, same
as any Next.js public env var.

## What's explicitly NOT covered here

Per `docs/PROJECT_CONTEXT.md`'s scope boundaries (Sprint 6 and Sprint 8): this MVP has no virus
scanning on uploaded receipts, no real email/WhatsApp sending, and no expense/actual-cost
tracking. None of that is a deployment configuration gap — it's product scope not yet built.
Don't try to "fix" it via environment variables; it needs actual feature work first.
