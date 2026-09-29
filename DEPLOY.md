# Deploying the demo to Render (free tier)

This repo has everything Render needs already: `render.yaml` (the Blueprint), plus
`backend/Dockerfile.render` and `frontend/Dockerfile.render` (production images, separate from
the dev-only root `Dockerfile`s used by `docker-compose.yml`). Both images have been built and
smoke-tested locally end-to-end (signup → login → dashboard, across two separate containers
talking over HTTP, matching how they'll run on Render).

## Why `render.yaml` isn't actually used below

`render.yaml` is kept in the repo as a reference for every env var each service needs (see it for
the exact list), but Render's **Blueprint** feature — the thing that reads `render.yaml` — turned
out to require a card on file too, same as their managed Postgres did. So does the Blueprint,
confirmed while setting this up, even with no database in the blueprint anymore. Creating the two
web services **individually** (New → Web Service, not New → Blueprint) does not ask for a card.
That's the path below.

## What you need to do

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
   - **Dockerfile Path**: `backend/Dockerfile.render`
   - **Docker Build Context Directory**: `backend` (if Render's form doesn't show this field
     separately, set **Root Directory** to `backend` instead and the Dockerfile Path to just
     `Dockerfile.render`)
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
   - **Dockerfile Path**: `frontend/Dockerfile.render`
   - **Docker Build Context Directory**: `frontend` (or **Root Directory**: `frontend` +
     Dockerfile Path `Dockerfile.render`, same fallback as step 3)
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
container boot (see `backend/Dockerfile.render`).

## If you rename either service in render.yaml

The two services' URLs are hardcoded as literal strings in `render.yaml` (`APP_URL`,
`FRONTEND_URL`, `NEXT_PUBLIC_API_BASE_URL`) because Render can't resolve a not-yet-created
service's URL on first apply. If you change `name: idp-backend` or `name: idp-frontend`, update
every URL that mentions the old name to match the new one, or the two services won't be able to
reach each other.

## Known free-tier tradeoffs for this demo

- **Cold starts, twice over.** Render's free web services spin down after ~15 min idle (~30-60s
  to wake up), and Neon's free project can idle/hibernate too — the first request after a while
  may be slow while both wake up. Normal, not a bug.
- **No persistent disk.** Anything uploaded through Media/Documents/change-order attachments is
  lost on the next restart or redeploy. Fine for a walkthrough, not for data you need to keep.
- **Emails don't actually send.** `MAIL_MAILER=log` (matching local dev) — password reset/OTP/
  notification emails get written to the backend service's log output (visible in Render's Logs
  tab) instead of being delivered. Swap in real `MAIL_*` env vars on the `idp-backend` service if
  you need real delivery for the demo.
- **APP_KEY is committed in render.yaml.** Fine for a disposable demo; rotate it or move it to
  Render's dashboard (`sync: false` in render.yaml) if this deployment ends up living longer than
  expected.
