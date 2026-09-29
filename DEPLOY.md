# Deploying the demo to Render (free tier)

This repo has everything Render needs already: `render.yaml` (the Blueprint), plus
`backend/Dockerfile.render` and `frontend/Dockerfile.render` (production images, separate from
the dev-only root `Dockerfile`s used by `docker-compose.yml`). Both images have been built and
smoke-tested locally end-to-end (signup → login → dashboard, across two separate containers
talking over HTTP, matching how they'll run on Render).

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

2. **Create the database on [neon.tech](https://neon.tech)**, not Render — confirmed while
   setting this up that Render now asks for a card to provision even its free Postgres, while
   Neon's free tier doesn't. Sign up (no card), create a project (any region is fine), and open
   its dashboard — you'll need the host, database name, username, and password from there in
   step 4 (Neon shows these either individually or as one connection string you can pick apart;
   the port is always `5432`).

3. **Sign up at [render.com](https://render.com)** for the two web services (no card needed for
   this part).

4. **New → Blueprint**, connect the GitHub repo you just pushed. Render reads `render.yaml` and
   shows two web services to create (`idp-backend`, `idp-frontend`), plus five environment
   variables it needs from you before it can apply: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`,
   `DB_PASSWORD` for `idp-backend` — paste in the matching values from Neon. Click **Apply**.

5. Wait for both services to finish their first build (~3-5 min each — the frontend does a full
   `next build`, the backend a `composer install`). You'll get two URLs:
   `https://idp-backend.onrender.com` and `https://idp-frontend.onrender.com`.

6. Open the frontend URL and use **Create an organization** (`/signup`) to make your first demo
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
