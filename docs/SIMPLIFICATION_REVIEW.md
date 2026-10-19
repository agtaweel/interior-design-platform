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
- **All of P1** (below) — shipped on the same branch after approval on 2026-10-19.

P2–P4 are still **proposals** — not built, because each one changes product behaviour.

---

## What a first-time user hits today

| # | Moment | What happens | Why it hurts a non-technical user |
|---|---|---|---|
| 1 | Opening the site | `/` redirects straight to the login form. | Nothing says what the product is or why to sign up. *(Fixed: `/` shows the guide to signed-out visitors — P1.1.)* |
| 2 | Login / signup | English only, no language switch. | *(Fixed.)* |
| 3 | Signup | "Organization name". | Owners think "company", not "organization". *(Fixed — P1.2.)* |
| 4 | First dashboard | Four zero KPIs and five empty panels. | No "what do I do first?" *(Fixed by the checklist.)* |
| 5 | Top navigation | 9 items (10 for platform owner): Leads, Clients, Inquiries, Media, Suppliers, Reports… | Leads vs Clients vs Inquiries is three words for "people who might pay me". Too many choices before the first job. |
| 6 | New project | Client must already exist — the form's first field is a dropdown of clients, empty on day one, with no "add new client" inside it. | Dead end: user has to know to leave, go to Clients, come back. |
| 7 | Inside a project | 12 tabs always visible (one of them permanently disabled), regardless of stage. | Contract / Payments / Snagging / Handover show on day one of a quote. Feels like a cockpit. *(Disabled tab removed — P1.5; stage grouping is P2.3.)* |
| 8 | BOQ | Empty table; "Apply Templates" is one button among Add Category / Add Room / Add Item / Import CSV. | The fastest path (template) isn't the obvious one. Terms: BOQ, Direct Cost, Category. |
| 9 | Pricing | Profit is a "rule" with type/method/applies-to; the project total only updates after **Recalculate**. | Forgetting Recalculate leaves the dashboard/reports value stale. *Correction:* proposals were never mis-priced by this — a new draft runs the calculator itself. The real gap was that a draft froze its prices at creation, and the UI had no way to refresh it, so BOQ edits made afterwards were silently missing from what was sent. *(Both fixed — P1.4.)* |
| 10 | Proposal | Create New Version → fill 6 fields → Save Draft → Send → copy link → copy OTP. | Six steps; the link and code are shown **once** and must be copied separately into WhatsApp. *(Copy step fixed — P1.3.)* |
| 11 | Client side | A client can receive three different links (portal, proposal approval, change-order approval); the approval pages are English-only. | Clients ask "which link do I open?" — support calls. |
| 12 | Change orders | After the client approves, nothing changes until staff press "Apply to BOQ & Contract". | Users assume approval = done; contract value silently stays old. |
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

### P2 — Flow changes (medium effort, biggest UX gain)

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

### P3 — Client-side simplification

1. **One link per client**: approvals happen *inside* the portal (Approve button + OTP on the
   proposal page) instead of separate per-document links. The portal link becomes the only thing
   staff ever send.
2. **Arabic on the approval pages** (`/p/proposals/…`, `/p/change-orders/…`) — they are
   English-only today while the portal itself is bilingual.

### P4 — Ongoing help

1. Contextual "?" hints next to the few genuinely technical fields (pricing rule "applies to",
   category vs room) linking into `/help#…`.
2. A short screen-recorded video per stage embedded in `/help` (Arabic voice-over).
3. Seed a **demo project** on signup (clearly labelled, one-click delete) so the user can click
   around a filled-in job before entering real data.

---

## Decisions needed before building P1–P3

- Label renames (P1.2) touch every screen and the Arabic guides — approve the wording first.
- P2.4 (auto-apply change orders) and P3.1 (in-portal approval) change the commercial/approval
  model documented in `PROJECT_CONTEXT.md` (signed links + OTP per document) — confirm before
  implementation; both stay within the "OTP, no e-signature vendor" locked decision.
