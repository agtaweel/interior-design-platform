# Deploying the demo (free tier)

Two platforms are documented here — **Koyeb** (try this first; no card required as of writing)
and **Render** (fallback; works, but both its Blueprint feature and its managed Postgres demand a
card, so it needs the individual-per-service workaround below). Either way you'll need
`backend/Dockerfile.production` and `frontend/Dockerfile.production` (production images, separate
from the dev-only root `Dockerfile`s used by `docker-compose.yml`) and a free Postgres database on
[neon.tech](https://neon.tech) (also card-free). All of this has been built and smoke-tested
locally end-to-end (signup → login → dashboard, across two separate containers talking over HTTP,
matching how they'll run on either platform).

## Deploying on Koyeb

1. **Push this repo to GitHub** if you haven't already:
   ```
   gh repo create interior-design-platform --private --source=. --remote=origin
   git add -A
   git commit -m "Prepare for deploy"
   git push -u origin master
   ```
   (or create the repo in the GitHub UI first, then `git remote add origin <url>` and push.)

2. **Create the database on [neon.tech](https://neon.tech)** (sign up, create a project, any
   region). From its dashboard grab the connection string — it looks like
   `postgresql://<user>:<password>@<host>/<database>?sslmode=require...`. You'll split that into
   the `DB_*` values below.

3. **Sign up at [koyeb.com](https://koyeb.com)** and connect your GitHub account when prompted.

4. **Create the backend service** — in the Koyeb control panel, create a new Web Service from
   your GitHub repo. The exact wording of these fields may differ slightly from what you see
   (Koyeb's UI changes over time) — look for the closest match:
   - **Builder**: Docker (not the auto-detected Buildpack)
   - **Dockerfile location / path**: `backend/Dockerfile.production`
   - **Work directory / build context**: `backend` (only if Koyeb exposes this as a separate
     field from the Dockerfile path — some UIs infer it from the Dockerfile's own location)
   - **Ports**: expose port `8000`, protocol HTTP — this must match `EXPOSE 8000` /
     the `${PORT:-8000}` fallback in `backend/Dockerfile.production`
   - **Instance type**: Free / Eco (whatever Koyeb calls its free tier nano instance)
   - **Region**: pick whichever region Koyeb offers on the free tier
   - **Environment variables** — add these (leave `FRONTEND_URL` as the placeholder for now,
     you'll fix it in step 7 once the frontend exists):
     ```
     APP_NAME=Interior Design Platform
     APP_ENV=production
     APP_DEBUG=false
     APP_KEY=base64:b/oSS29NFvK42rYNGuiOEoS+ocMgm++NWmqNDIbKT+c=
     APP_URL=http://localhost:8000
     FRONTEND_URL=http://localhost:3000
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
   Deploy it, wait for the build to finish (~3-5 min — `composer install` takes most of that),
   then **copy the service's public URL** from the Koyeb dashboard (something like
   `https://idp-backend-<your-org>.koyeb.app` — Koyeb's URLs aren't predictable ahead of time the
   way Render's are, so you have to read the real one off the dashboard).

5. **Go back into the backend service's env vars and fix `APP_URL`** to the real URL from step 4.

6. **Create the frontend service** the same way:
   - **Dockerfile location**: `frontend/Dockerfile.production`
   - **Work directory / build context**: `frontend`
   - **Ports**: expose port `3000`, protocol HTTP
   - **Environment variables**:
     ```
     NEXT_PUBLIC_API_BASE_URL=<backend's real URL from step 4>/api/v1
     ```
     This has to be right *before* the first build — it's baked into the frontend's JS bundle at
     build time, not read at runtime. If you need to change it later, you must trigger a fresh
     deploy (not just save the env var) for the new value to actually take effect.
   Deploy it, wait for the build, then copy **its** public URL too.

7. **Go back to the backend service one more time** and fix `FRONTEND_URL` to the frontend's real
   URL from step 6 (this only affects links inside emails — password reset, proposal/change-order
   approval links — so the app itself works fine even before you do this; just do it before
   relying on any of those emailed links).

8. Open the frontend's URL and use **Create an organization** (`/signup`) to make your first demo
   account — no manual seed step needed, the backend seeds RBAC roles automatically on every boot.

If Koyeb also asks for a card at any point before you've created anything, stop and tell me —
that would mean it's no longer the card-free option I thought it was, and we should try
Northflank next rather than fighting it further.

---

## Deploying on Render (fallback)

### Why `render.yaml` isn't actually used below

`render.yaml` is kept in the repo as a reference for every env var each service needs (see it for
the exact list), but Render's **Blueprint** feature — the thing that reads `render.yaml` — turned
out to require a card on file too, same as their managed Postgres did. So does the Blueprint,
confirmed while setting this up, even with no database in the blueprint anymore. Creating the two
web services **individually** (New → Web Service, not New → Blueprint) does not ask for a card.
That's the path below.

### What you need to do

1. **Push this repo to GitHub** (Render deploys from a git remote — it doesn't accept a local
   folder). If you don't have a remote yet:
   ```
   gh repo create interior-design-platform --private --source=. --remote=origin
   git add -A
   git commit -m "Prepare for Render deploy"
   git push -u origin master
   ```
   (or create the repo in the GitHub UI first, then `git remote add origin <url>` and push.)

2. **Create the database on [neon.tech](https://neon.tech)**, not Render (Render's Postgres
   needs a card; Neon's free tier doesn't). Sign up, create a project (any region), and open its
   dashboard — you need the host, database name, username, and password from there (Neon shows
   these individually or as one connection string you can pick apart; the port is always `5432`).

3. **On [render.com](https://render.com), click New → Web Service** (not Blueprint) and connect
   the GitHub repo. Configure it as:
   - **Name**: `idp-backend`
   - **Runtime**: Docker
   - **Dockerfile Path**: `backend/Dockerfile.production`
   - **Docker Build Context Directory**: `backend` (if Render's form doesn't show this field
     separately, set **Root Directory** to `backend` instead and the Dockerfile Path to just
     `Dockerfile.production`)
   - **Instance Type**: Free
   - **Environment Variables** — paste this block in (Render has a "paste from .env" option in
     the Environment tab that accepts this directly), filling in the four `<from Neon>` values:
     ```
     APP_NAME=Interior Design Platform
     APP_ENV=production
     APP_DEBUG=false
     APP_KEY=base64:b/oSS29NFvK42rYNGuiOEoS+ocMgm++NWmqNDIbKT+c=
     APP_URL=https://idp-backend.onrender.com
     FRONTEND_URL=https://idp-frontend.onrender.com
     LOG_CHANNEL=stderr
     DB_CONNECTION=pgsql
     DB_SSLMODE=require
     DB_HOST=<from Neon>
     DB_PORT=5432
     DB_DATABASE=<from Neon>
     DB_USERNAME=<from Neon>
     DB_PASSWORD=<from Neon>
     SESSION_DRIVER=database
     CACHE_STORE=database
     QUEUE_CONNECTION=database
     MAIL_MAILER=log
     ```
   Click **Create Web Service**.

4. **Once it's created, check the URL Render actually assigned** (top of the service page — it's
   usually `https://idp-backend.onrender.com`, but Render appends a random suffix instead if that
   exact name was already taken by someone else, e.g. `https://idp-backend-ab12.onrender.com`).
   If it's not the plain name, go back into this service's Environment tab and fix `APP_URL` to
   match the real one.

5. **New → Web Service** again for the frontend:
   - **Name**: `idp-frontend`
   - **Runtime**: Docker
   - **Dockerfile Path**: `frontend/Dockerfile.production`
   - **Docker Build Context Directory**: `frontend` (or **Root Directory**: `frontend` +
     Dockerfile Path `Dockerfile.production`, same fallback as step 3)
   - **Instance Type**: Free
   - **Environment Variables**:
     ```
     NEXT_PUBLIC_API_BASE_URL=https://idp-backend.onrender.com/api/v1
     ```
     Use the backend's **real** URL from step 4 here, not the placeholder, since this value gets
     baked into the frontend's JS bundle at build time — if you fix it after the first build,
     you'll need to trigger a fresh deploy (not just save the env var) for it to take effect.
   Click **Create Web Service**.

6. Wait for both services to finish their first build (~3-5 min each — the frontend does a full
   `next build`, the backend a `composer install`).

7. Open the frontend's URL and use **Create an organization** (`/signup`) to make your first demo
   account — it logs you straight in, no seed data step required (the backend seeds the RBAC
   roles automatically on boot; see below).

That's it — no manual migrate step either. Migrations + role seeding run automatically on every
container boot (see `backend/Dockerfile.production`).

### If you rename either service in render.yaml

The two services' URLs are hardcoded as literal strings in `render.yaml` (`APP_URL`,
`FRONTEND_URL`, `NEXT_PUBLIC_API_BASE_URL`) because Render can't resolve a not-yet-created
service's URL on first apply. If you change `name: idp-backend` or `name: idp-frontend`, update
every URL that mentions the old name to match the new one, or the two services won't be able to
reach each other.

---

## Known free-tier tradeoffs (applies to either platform)

- **Cold starts, twice over.** Free web services on both Koyeb and Render spin down after a
  period of idle (Render: ~15 min; Koyeb's free instances behave similarly) and take ~30-60s to
  wake back up on the next request. Neon's free project can idle/hibernate too. The first request
  after a while may be slow while everything wakes up — normal, not a bug.
- **No persistent disk.** Anything uploaded through Media/Documents/change-order attachments is
  lost on the next restart or redeploy on either platform's free tier. Fine for a walkthrough,
  not for data you need to keep.
- **Emails don't actually send.** `MAIL_MAILER=log` (matching local dev) — password reset/OTP/
  notification emails get written to the backend service's log output instead of being
  delivered. Both platforms have a Logs tab where you can read them. Swap in real `MAIL_*` env
  vars on the backend service if you need real delivery for the demo.
- **APP_KEY is a fresh, disposable value** generated for this deploy and reused across both
  platforms' instructions above. Fine for a demo; rotate it (`php artisan key:generate --show`
  locally, then update the env var) if this deployment ends up living longer than expected.
- **The Neon connection string is sensitive.** If it's ever been pasted somewhere outside your
  own notes, rotate the database password from Neon's dashboard once you're done experimenting.
