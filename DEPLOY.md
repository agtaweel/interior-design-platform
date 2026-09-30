# Deploying the demo (free tier)

Three platforms are documented here, in the order to try them:

1. **Northflank** — current pick.
2. **Koyeb** — alternate, if Northflank doesn't pan out.
3. **Render** — fallback that's confirmed to work, but only via the individual-per-service
   workaround below (Render's Blueprint feature *and* its managed Postgres both demand a card).

All three build from the same two files — `backend/Dockerfile.production` and
`frontend/Dockerfile.production` — which have been built and smoke-tested locally end-to-end
(signup → login → dashboard, across two separate containers talking over HTTP). The database is
[neon.tech](https://neon.tech) regardless of which platform hosts the app, since it's the one
Postgres provider in this whole search that's stayed card-free.

If whichever platform you're on asks for a card before you've created anything, stop and tell me
— don't hand one over. We'll cross it off the list and move to the next.

## Shared prerequisites (do this once, no matter which platform you pick)

1. **Push this repo to GitHub**, if you haven't already:
   ```
   git add -A
   git commit -m "Prepare for deploy"
   git push
   ```
   (If there's no remote yet: create the repo in the GitHub UI, then
   `git remote add origin <url>` before pushing.)

2. **Create a database on [neon.tech](https://neon.tech)** — sign up, create a project (any
   region), and open its dashboard for the connection string. It looks like
   `postgresql://<user>:<password>@<host>/<database>?sslmode=require...` — you'll split that into
   the `DB_*` values below.

## Environment variables reference

Every platform needs the same two sets of variables. `<BACKEND_URL>` and `<FRONTEND_URL>` below
mean "the real public URL the platform assigns to that service" — none of these platforms let you
predict that URL before the service exists, so the sequence is always: create the backend first
with a placeholder, note its real URL, create the frontend with that real URL baked in, then go
back and fix the backend's placeholder.

**Backend service** — port **8000**:
```
APP_NAME=Fitout
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:b/oSS29NFvK42rYNGuiOEoS+ocMgm++NWmqNDIbKT+c=
APP_URL=<BACKEND_URL>
FRONTEND_URL=<FRONTEND_URL>
LOG_CHANNEL=stderr
DB_CONNECTION=pgsql
DB_SSLMODE=require
DB_HOST=<from your Neon connection string>
DB_PORT=5432
DB_DATABASE=<from your Neon connection string>
DB_USERNAME=<from your Neon connection string>
DB_PASSWORD=<from your Neon connection string>
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
```
(`APP_URL` only matters for the backend's own generated links, and `FRONTEND_URL` only affects
links inside emails — password reset, proposal/change-order approval links — so the core app
works fine even with a placeholder in either one until you circle back and fix it.)

**Frontend service** — port **3000**:
```
NEXT_PUBLIC_API_BASE_URL=<BACKEND_URL>/api/v1
```
This one you cannot fix later without a rebuild — `NEXT_PUBLIC_*` values are inlined into the
frontend's JS bundle at `npm run build` time, not read at runtime. Get the backend's real URL
*before* you create the frontend service.

Migrations and RBAC role seeding run automatically on every backend container boot — there's no
manual `artisan migrate`/`db:seed` step on any of these platforms.

---

## Platform 1: Northflank

1. Sign up at [northflank.com](https://northflank.com) and connect your GitHub account.

2. **Create a Project** (Northflank groups services under a Project — one project for both
   services here is fine).

3. **Add a Service → deploy from Git repository**, pick this repo, and configure the backend:
   - **Build type**: Dockerfile (not Buildpack)
   - **Dockerfile path**: `backend/Dockerfile.production`
   - **Build context**: `backend` (Northflank may infer this from the Dockerfile path instead of
     asking separately — if there's only one path field, that's why)
   - **Ports**: add port `8000`, public/HTTP
   - **Plan**: the free compute plan
   - **Environment variables**: paste in the backend block above (`APP_URL`/`FRONTEND_URL` as
     placeholders for now — `http://localhost:8000` / `http://localhost:3000` work fine)
   - Deploy, wait for the build (~3-5 min, mostly `composer install`), then copy the **real
     public URL** Northflank assigns (their default domains look like
     `https://<service>--<project>--<hash>.code.run` — not predictable ahead of time, read it off
     the dashboard).

4. Go back into the backend service's env vars and fix `APP_URL` to that real URL.

5. **Add a second Service** the same way for the frontend:
   - **Dockerfile path**: `frontend/Dockerfile.production`
   - **Build context**: `frontend`
   - **Ports**: add port `3000`, public/HTTP
   - **Environment variables**: `NEXT_PUBLIC_API_BASE_URL=<backend's real URL>/api/v1`
   - Deploy, then copy **its** real public URL too.

6. Go back to the backend service one more time and fix `FRONTEND_URL` to the frontend's real URL.

7. Open the frontend's URL and use **Create an organization** (`/signup`) to make your first demo
   account.

Field names above are my best current understanding — Northflank's UI may label things slightly
differently. If something doesn't match, describe what you see and I'll adjust.

---

## Platform 2: Koyeb (alternate)

Same shape as Northflank, different dashboard:

1. Sign up at [koyeb.com](https://koyeb.com), connect GitHub.
2. **Create Web Service** from the repo:
   - **Builder**: Docker
   - **Dockerfile location**: `backend/Dockerfile.production`
   - **Work directory / build context**: `backend` (if shown separately)
   - **Ports**: `8000`, HTTP
   - **Instance type**: Free/Eco
   - **Environment variables**: the backend block above
   - Deploy, copy the real `.koyeb.app` URL once it's up.
3. Fix `APP_URL` on the backend to that real URL.
4. **Create Web Service** again for the frontend: Dockerfile `frontend/Dockerfile.production`,
   context `frontend`, port `3000`, env var `NEXT_PUBLIC_API_BASE_URL=<backend URL>/api/v1`.
5. Fix the backend's `FRONTEND_URL` to the frontend's real URL once you have it.
6. `/signup` on the frontend's URL to create your first demo account.

---

## Platform 3: Render (fallback)

Render works, but needs one workaround: **use New → Web Service, not New → Blueprint** — the
Blueprint feature (and Render's own managed Postgres) both require a card; creating each web
service individually does not.

1. **New → Web Service**, connect the repo, configure the backend:
   - **Runtime**: Docker
   - **Dockerfile Path**: `backend/Dockerfile.production`
   - **Docker Build Context Directory**: `backend` (or set **Root Directory** to `backend` and
     the Dockerfile Path to just `Dockerfile.production`, if that's the only path field shown)
   - **Instance Type**: Free
   - **Environment Variables**: the backend block above (Render's Environment tab has a "paste
     from .env" option that accepts the whole block directly)
   - Create it, then check the URL Render assigned (usually `https://<name>.onrender.com`, but a
     random suffix gets appended if that exact name is already taken by someone else).
2. Fix `APP_URL` on the backend to the real URL.
3. **New → Web Service** again for the frontend: Dockerfile Path `frontend/Dockerfile.production`,
   context `frontend`, env var `NEXT_PUBLIC_API_BASE_URL=<backend's real URL>/api/v1`.
4. Fix the backend's `FRONTEND_URL` to the frontend's real URL.
5. `/signup` on the frontend's URL.

`render.yaml` in the repo root documents the same env vars in Render's Blueprint format, in case
you ever want to revisit the Blueprint path (e.g. if you decide a card is fine after all) —
it's not applied by any of the steps above.

---

## Known free-tier tradeoffs (applies to all three)

- **Cold starts.** Free web services on every platform here spin down after a period of idle and
  take tens of seconds to wake back up on the next request. Neon's free project can idle too.
  Normal, not a bug.
- **No persistent disk.** Anything uploaded through Media/Documents/change-order attachments is
  lost on the next restart or redeploy. Fine for a walkthrough, not for data you need to keep.
- **Emails don't actually send.** `MAIL_MAILER=log` (matching local dev) — password reset/OTP/
  notification emails get written to the backend service's log output instead of being
  delivered. Every platform here has a Logs tab where you can read them. Swap in real `MAIL_*`
  env vars if you need real delivery for the demo.
- **APP_KEY is a fresh, disposable value** generated for this deploy and reused across every
  platform's instructions above. Fine for a demo; rotate it (`php artisan key:generate --show`
  locally, then update the env var) if this deployment ends up living longer than expected.
- **The Neon connection string is sensitive.** If it's ever been pasted somewhere outside your
  own notes, rotate the database password from Neon's dashboard once you're done experimenting.
