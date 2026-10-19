# Simplification review — the main flow through a non-technical user's eyes

Date: 2026-10-19. Method: walked the real app as a brand-new company (signup through the actual
form, Arabic UI) and traced every screen between "I just heard about Fitout" and "my client has a
proposal on their phone", reading each screen's code for what it asks of the user. The persona:
a finishing-company owner who runs the business on WhatsApp and Excel, reads Arabic first, and
has never used a business system.

What already shipped from this review (branch `worktree-simplify-flow-and-guides`):

- **Public guide** at `/guide` (no login) — what Fitout is, the 5-step project journey, what the
  client sees, a plain-language glossary, FAQ. Linked from login and signup.
- **In-app guide** at `/help` (the "?" in the header) — organised by project stage, every step
  names the exact button and where it is.
- **Getting-started checklist** on the dashboard — 4 steps to a first proposal, ticked from real
  data, hideable, disappears when done.
- **Language switch on the signed-out screens** (login, signup, forgot/reset password) — before
  this, an Arabic speaker could not switch language until after signing up.
- **All of P1–P4** (below) — shipped on the same branch after approval on 2026-10-19.

---

## What a first-time user hits today

| # | Moment | What happens | Why it hurts a non-technical user |
|---|---|---|---|
| 1 | Opening the site | `/` redirects straight to the login form. | Nothing says what the product is or why to sign up. *(Fixed: `/` shows the guide to signed-out visitors — P1.1.)* |
| 2 | Login / signup | English only, no language switch. | *(Fixed.)* |
| 3 | Signup | "Organization name". | Owners think "company", not "organization". *(Fixed — P1.2.)* |
| 4 | First dashboard | Four zero KPIs and five empty panels. | No "what do I do first?" *(Fixed by the checklist.)* |
| 5 | Top navigation | 9 items (10 for platform owner): Leads, Clients, Inquiries, Media, Suppliers, Reports… | Leads vs Clients vs Inquiries is three words for "people who might pay me". Too many choices before the first job. *(Fixed — P2.2.)* |
| 6 | New project | Client must already exist — the form's first field is a dropdown of clients, empty on day one, with no "add new client" inside it. | Dead end: user has to know to leave, go to Clients, come back. *(Fixed — P2.1.)* |
| 7 | Inside a project | 12 tabs always visible (one of them permanently disabled), regardless of stage. | Contract / Payments / Snagging / Handover show on day one of a quote. Feels like a cockpit. *(Disabled tab removed — P1.5; stage grouping is P2.3.)* |
| 8 | BOQ | Empty table; "Apply Templates" is one button among Add Category / Add Room / Add Item / Import CSV. | The fastest path (template) isn't the obvious one. Terms: BOQ, Direct Cost, Category. |
| 9 | Pricing | Profit is a "rule" with type/method/applies-to; the project total only updates after **Recalculate**. | Forgetting Recalculate leaves the dashboard/reports value stale. *Correction:* proposals were never mis-priced by this — a new draft runs the calculator itself. The real gap was that a draft froze its prices at creation, and the UI had no way to refresh it, so BOQ edits made afterwards were silently missing from what was sent. *(Both fixed — P1.4.)* |
| 10 | Proposal | Create New Version → fill 6 fields → Save Draft → Send → copy link → copy OTP. | Six steps; the link and code are shown **once** and must be copied separately into WhatsApp. *(Copy step fixed — P1.3.)* |
| 11 | Client side | A client can receive three different links (portal, proposal approval, change-order approval); the approval pages are English-only. | Clients ask "which link do I open?" — support calls. *(Fixed — P3.)* |
| 12 | Change orders | After the client approves, nothing changes until staff press "Apply to BOQ & Contract". | Users assume approval = done; contract value silently stays old. *(Optional auto-apply + a "Next step" prompt — P2.4/P2.3.)* |
| 13 | Jargon | BOQ, Snagging, Handover, Change Orders, Execution, OTP, Gross Profit. | Trade/English terms; fine for engineers, opaque for owners and clients. |

---

## Proposals, in priority order

### P1 — Quick wins ✅ shipped

What was built, and where it differs from the original proposal:

- **P1.1** — `app/page.tsx` waits for the auth check, then shows the guide (signed out) or
  redirects to `/dashboard` (signed in).
- **P1.2** — renamed in UI strings and both guides: Company, Items & Prices, Site Work,
  Extras & Changes, Fix List (subtitles keep the trade term in brackets). Body sentences that use
  the trade terms were left as-is. **Not done:** merging Leads + Inquiries — that is the P2.2
  navigation change.
- **P1.3** — `WhatsAppShare` on both send-result cards (proposal and change order): one message in
  the UI language with greeting, link, code; "Send to <client> on WhatsApp" opens `wa.me` straight
  to the client's number (Egyptian local numbers normalised to `20…`), otherwise lets WhatsApp
  pick the contact. Still click-to-chat only — no WhatsApp API, so the locked "manual sharing"
  decision holds.
- **P1.4** — backend: `ProjectPricingObserver` on `BoqItem`/`PricingRule` keeps the project's
  pricing cache current via `ProjectPricingCache` (deferred to commit + de-duplicated inside
  transactions, so imports reprice once; writes only when a total changes). Frontend: a draft
  proposal re-snapshots current items/pricing whenever it's opened; the pricing panel re-reads
  after BOQ edits; "Recalculate" is now a secondary "Refresh totals".
- **P1.5** — removed the disabled Activity tab, plus two dead cards on the project overview
  (a permanent 0% "Progress" bar captioned "arrives in a later sprint", and a "Recent Activity"
  card that always said "No activity recorded yet").

Original proposal:

1. **`/` shows the guide to signed-out visitors** instead of bouncing to login (auth is client-side,
   so: render the guide, and redirect to `/dashboard` only if a token exists).
2. **Plain-language labels**, keeping the trade term as a hint where professionals expect it:
   - "Organization" → **Company** / "شركة"
   - "BOQ & Pricing" → **Items & Prices** / "البنود والأسعار"
   - "Execution" → **Site work** / "شغل الموقع"
   - "Change Orders" → **Extras & changes** / "الإضافات والتعديلات"
   - "Snagging" → **Fix list** / "قائمة الإصلاحات"
   - "Leads" + "Inquiries" → one **New requests** / "طلبات جديدة" (see P2.2)
3. **One "Copy WhatsApp message" button** after Send to Client: one paste containing a greeting,
   the link and the code, plus a `wa.me/<client phone>` button when the client has a phone. Same for
   change orders. Removes the "shown once, copy two things" trap.
4. **Auto-recalculate pricing** after any BOQ or rule edit (or at least before a proposal version is
   created), so "Recalculate" can't be forgotten.
5. **Hide the disabled "Activity" tab** until it exists.

### P2 — Flow changes ✅ shipped

What was built, and where it differs from the original proposal:

- **P2.1 — New-project wizard** at `/projects/new` (client → the job → start from). Pick or type a
  client (name + mobile); for a returning client, choose one of their saved places (one place is
  pre-selected) or a new one with type + area; name (suggested from client + place); optional
  start/target dates. Step 3 offers Standard / Premium / Luxury full finishing (Standard
  suggested), the renovation templates, or empty; lands on Items & Prices already filled.
  *Differences:* templates are suggested by finishing level, not by property type — the seeded
  templates carry no `project_type`, and every template quantity is a fixed default, so area
  doesn't scale quantities (it's saved on the property). Built by chaining the existing endpoints
  rather than a new all-in-one endpoint, remembering what's already created so a retry never
  duplicates. The old inline form is removed; `?new=1` and `?new_client_id=` redirect into the
  wizard.
- **P2.2 — Nav cut to 5**: Home · Requests · Clients · Projects · More ▾ (Media, Suppliers,
  Reports, Settings, Help; + Platform for the platform owner). Leads and Inquiries stay separate
  screens under one "Requests" entry with a shared tab bar. The bar now fits from 1024px (was
  1280px).
- **P2.3 — Stage-grouped project tabs + "Next step" card**, driven by a new read-only
  `GET /projects/{id}/journey` (stage + next step from existing records; counts/statuses only, no
  money). *Difference:* later stages are dimmed with a hint but **still clickable**, not locked —
  real jobs do order materials or log site visits before signing.
- **P2.4 — Auto-apply approved change orders**, as an **opt-in per company, off by default**
  (More → Settings → Extras & Changes; Owner only). When on, the client's approval applies the
  change order to the items and contract in the same transaction. It never makes the approval
  fail: it only runs when a contract exists, inside its own savepoint, and otherwise leaves the
  change order "approved" for manual apply. The ledger entry is attributed to the staff member who
  created the change order (the public approval has no logged-in user). Stored as a typed column
  `organizations.auto_apply_change_orders`, not in `settings_json`. *Not built:* the "undo within
  X minutes" variant.

Original proposal:

1. **New-project wizard (3 screens)** — replaces Client → Property → Project → BOQ hopping:
   1. *Who's it for?* pick a client **or type a new name + phone inline**;
   2. *What's the job?* name + type (apartment/villa/office) + area;
   3. *Start from:* a suggested template for that type, or empty.

   Lands the user in an already-filled BOQ. Property details become optional, later.
2. **Collapse the nav to 5**: Home · Requests (Leads+Inquiries) · Clients · Projects · More
   (Suppliers, Media, Reports, Settings, Help).
3. **Stage-based project screen**: replace the 12 flat tabs with a stepper —
   **Quote** (Items & Prices, Proposal) → **Agreement** (Contract, Payments) → **Work** (Site work,
   Expenses, Extras) → **Finish** (Fix list, Handover) — plus Documents always available. Future
   stages are visible but greyed with "available after the client approves". The Overview shows a
   single **"Next step"** card ("Client approved — Convert to Contract").
4. **Approving a change order applies it** (single transaction), with an "undo within X minutes"
   or an explicit "review before applying" setting for companies that want the gate. Today's
   two-step is safe but invisible to users.

### P3 — Client-side simplification ✅ shipped

What was built:

- **P3.1 — One link per client.** The portal link is now the only link staff send. It's created
  on the first send and reused by every later one, and staff can copy it again any time from
  Project → Overview → Client Portal (plus a WhatsApp button). To make that possible the portal
  token is stored **encrypted with the app key** next to its hash — portal links only; one-time
  approval links stay hash-only, and a DB dump alone still yields no usable link. Clients approve
  / request changes / reject **inside the portal** with the per-document 6-digit code; the
  WhatsApp message and the OTP email now carry the portal link. The older per-document links keep
  working for anything already sent. Both paths share one implementation of the rules
  (`ProposalClientDecision`, `ChangeOrderClientDecision`). Revoke (no token needed) closes every
  portal link of the project; the next send issues a new one.
  *Also fixed on the way:* an already **applied** change order could be approved again (the
  "already approved" guard only checked `approved`); with P2.4 auto-apply a client double-tap
  could have reached that. Now refused with 409.
- **P3.2 — Arabic on the approval pages.** `/p/proposals/…` and `/p/change-orders/…` (and the new
  in-portal decision panel) are fully bilingual, with a language button; error messages are
  translated by code rather than showing the server's English text; Arabic number agreement for
  timeline days.

Original proposal:

1. **One link per client**: approvals happen *inside* the portal (Approve button + OTP on the
   proposal page) instead of separate per-document links. The portal link becomes the only thing
   staff ever send.
2. **Arabic on the approval pages** (`/p/proposals/…`, `/p/change-orders/…`) — they are
   English-only today while the portal itself is bilingual.

### P4 — Ongoing help ✅ shipped

What was built, and where it differs from the original proposal:

- **P4.1 — Contextual hints.** A "?" (`HelpHint`) next to the technical fields — pricing rule
  type / method / "applies to", BOQ categories vs rooms, change-order item action, the
  "mandatory" fix flag, and Convert to Contract — with a one-line explanation and "Learn more"
  into the matching `/help` glossary entry (every entry now has an anchor; three new entries).
- **P4.2 — Walkthrough videos.** One short recording per stage (Quote, Sign, Build, Finish),
  recorded from the real app by `demo/scripts/record_help_videos.mjs`, **in both UI languages**
  (the player picks the one matching the interface), each with Arabic and English subtitles timed
  to the actions, plus a poster frame. ~2.8 MB in `frontend/public/help/videos`.
  *Difference:* **no voice-over** — a human Arabic narration can't be produced here; the
  subtitles carry the narration, and a narrated MP4 can replace any clip later under the same
  file name. Re-run the script after UI changes.
- **P4.3 — Sample project.** *Difference:* created **on one click** ("Explore a sample project"
  on the dashboard checklist and in Help), not on every signup — seeding every new company
  would put fake numbers in their dashboard. It's a 3-bedroom apartment with Standard
  full-finishing items, a 15% markup and a written draft proposal, in the user's language;
  flagged `is_sample` on the project and its client, shown with a banner on every tab and a
  badge in lists, excluded from reports/dashboard money figures and the getting-started
  checklist, and deleted in one click with everything attached (even after contract/payments).

Original proposal:

1. Contextual "?" hints next to the few genuinely technical fields (pricing rule "applies to",
   category vs room) linking into `/help#…`.
2. A short screen-recorded video per stage embedded in `/help` (Arabic voice-over).
3. Seed a **demo project** on signup (clearly labelled, one-click delete) so the user can click
   around a filled-in job before entering real data.

---

## Decisions still open

- **P2.4 default.** Auto-apply ships **off** for every company. Should new companies default to
  on? (Existing companies should stay off unless they opt in.)
- **Retrievable portal links (P3.1).** Storing the portal token encrypted is a deliberate
  trade-off for "staff can always resend the one link". If you'd rather keep hash-only storage,
  the alternative is regenerating the link on demand (which invalidates the client's bookmark).
