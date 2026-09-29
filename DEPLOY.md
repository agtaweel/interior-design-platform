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

2. **Sign up at [render.com](https://render.com)** (free, no credit card required for the Starter
   web service / free Postgres tiers as of this writing — Render's terms can change, so if
   you're asked for a card at some step, that's Render, not something in this setup).

3. **New → Blueprint**, connect the GitHub repo you just pushed. Render will read `render.yaml`
   and show you three resources to create: `idp-postgres` (database), `idp-backend`, `idp-frontend`
   (web services). Click **Apply**.

4. Wait for both services to finish their first build (~3-5 min each — the frontend does a full
   `next build`, the backend a `composer install`). You'll get two URLs:
   `https://idp-backend.onrender.com` and `https://idp-frontend.onrender.com`.

5. Open the frontend URL and use **Create an organization** (`/signup`) to make your first demo
   account — it logs you straight in, no seed data step required (the backend seeds the RBAC
   roles automatically on boot; see below).

That's it — no manual database setup, no separate migrate step. Everything in `render.yaml` is
wired to run automatically on container boot.

## If you rename either service in render.yaml

The two services' URLs are hardcoded as literal strings in `render.yaml` (`APP_URL`,
`FRONTEND_URL`, `NEXT_PUBLIC_API_BASE_URL`) because Render can't resolve a not-yet-created
service's URL on first apply. If you change `name: idp-backend` or `name: idp-frontend`, update
every URL that mentions the old name to match the new one, or the two services won't be able to
reach each other.

## Known free-tier tradeoffs for this demo

- **Cold starts.** Free web services spin down after ~15 min idle; the first request after that
  takes ~30-60s while it wakes back up. Normal, not a bug.
- **No persistent disk.** Anything uploaded through Media/Documents/change-order attachments is
  lost on the next restart or redeploy. Fine for a walkthrough, not for data you need to keep.
- **Free Postgres expiry.** Render's free Postgres plan has historically had a time-boxed free
  window before it needs upgrading — check current terms when you create it in step 3.
- **Emails don't actually send.** `MAIL_MAILER=log` (matching local dev) — password reset/OTP/
  notification emails get written to the backend service's log output (visible in Render's Logs
  tab) instead of being delivered. Swap in real `MAIL_*` env vars on the `idp-backend` service if
  you need real delivery for the demo.
- **APP_KEY is committed in render.yaml.** Fine for a disposable demo; rotate it or move it to
  Render's dashboard (`sync: false` in render.yaml) if this deployment ends up living longer than
  expected.
