# Demo Script — Nile & Co. Interiors

A shot-by-shot walkthrough script for the Interior Design & Finishing Management Platform demo.
Used to drive the recorded video (`walkthrough.mp4`) and the companion PDF
(`Interior_Design_Platform_Demo.pdf`). Written so it can also be read standalone as a narration
script if someone wants to re-record this with a live voiceover later.

**Scenario**: Sara Hassan, owner of a fictional Cairo studio "Nile & Co. Interiors," is running a
renovation project for client Laila Farouk's penthouse in Zamalek — from BOQ through to a
mid-project change order.

---

## 1. Sign in
**Screen**: Login (S01)
**Action**: Enter `sara@nileandco.example` / `password`, sign in.
**Narration**: "This is the Interior Design & Finishing Management Platform — a multi-tenant
system for design studios to run a project from first quote through to final handover, in EGP,
with Arabic and English support throughout."

## 2. Dashboard
**Screen**: Main Dashboard (S02)
**Action**: Pause on the KPI cards and project list.
**Narration**: "The dashboard gives an owner a one-screen view of active projects, pending
proposals, and receivables."

## 3. Client profile
**Screen**: Client Profile (S04)
**Action**: Navigate to Clients → Laila Farouk. Show contact info and linked property/project.
**Narration**: "Every client has a single view: contact details, their properties, and every
project we've run for them."

## 4. Project overview
**Screen**: Project Overview (S06)
**Action**: Open "Zamalek Penthouse Renovation." Show status and the Financials block (still
zero at this point — nothing priced/sent/collected yet).
**Narration**: "This project starts empty on the commercial side — value, collected, and
outstanding all populate automatically as we move through the workflow."

## 5. BOQ Builder
**Screen**: BOQ & Pricing → BOQ (S07)
**Action**: Show the category tree (Flooring, Painting, Electrical, Kitchen) and room filter
(Living Room, Kitchen, Master Bedroom). Click into the line-item grid, show a cost breakdown for
one row (material/labor/other → direct cost, client price → client total).
**Narration**: "The BOQ is a full spreadsheet-style bill of quantities — categories, rooms, and
per-line costs versus client-facing pricing, calculated live as you type."

## 6. Pricing Panel
**Screen**: BOQ & Pricing → Pricing (S08)
**Action**: Scroll to the Pricing panel. Show the two configured rules (12% Contractor Markup on
direct cost, 10% Design & Supervision Fee on the client subtotal). Click **Recalculate**. Show
the resulting breakdown: direct cost → markup → client subtotal → fee → grand total
(EGP 258,713.20). Toggle to **Client Preview** to show only the final number is ever exposed.
**Narration**: "Markup and fee rules stack on top of the BOQ automatically. Internally we see
every layer of the math — a client only ever sees the bottom line."

## 7. Proposal — author and send
**Screen**: Proposal (S09)
**Action**: Create a new proposal version. Fill in cover note and scope. Save draft, then
**Send**. Show the resulting public link + OTP code displayed for copying.
**Narration**: "Sending a proposal snapshots the BOQ and pricing into an immutable version, and
generates a secure link with a one-time code — the two things staff share with the client
manually, since there's no messaging integration in this MVP."

## 8. Client Proposal Portal (public)
**Screen**: Client Proposal Portal (S11) — mobile-first, unauthenticated
**Action**: Navigate to the public link. Show the client-facing view: scope, line items, final
total — no internal cost data anywhere. Approve with the OTP code.
**Narration**: "This is what the client actually sees when they open the link on their phone —
scope, price, and an Approve action gated by the OTP code we just generated. No login required."

## 9. Contract
**Screen**: Contract (S12)
**Action**: Back in the internal app, open the Contract tab. Click **Convert to Contract**. Show
the resulting contract: value locked to the approved proposal's total, terms editable.
**Narration**: "Once approved, converting to a contract is one click — the contract value is
permanently tied to what the client actually approved, never recalculated behind their back."

## 10. Payments
**Screen**: Payments (S13)
**Action**: Add a payment schedule (50% deposit). Record a payment against it with a receipt
upload. Show the schedule flip to "Paid" and the receivables update.
**Narration**: "Payment schedules and receipts are tracked per project — partial payments are
supported, and every receipt is stored and retrievable."

## 11. Change Order
**Screen**: Change Orders (S14) + public approval page
**Action**: Create a change order (e.g. add extra kitchen lighting). Send it, approve it via the
public link, then click **Apply**. Show the contract value increase by exactly the change order's
price delta, and the new line item appear in the BOQ.
**Narration**: "Scope changes after the contract is signed go through a controlled, auditable
change order — approved by the client the same way, then applied in one click, which is the only
way a contract's value is ever allowed to move after signing."

## 12. Reports
**Screen**: Reports (S21)
**Action**: Open the organization-wide Reports page. Show KPI cards (revenue, receivables,
estimated margin, change order value) and the per-project table.
**Narration**: "Reports roll every project up into one view for the owner — revenue collected,
what's still outstanding, and estimated margin, clearly labeled as an estimate since it's derived
from BOQ pricing, not real expense tracking."

## 13. Notifications
**Screen**: Notification bell (any authenticated page)
**Action**: Click the bell. Show the notification feed (proposal approved, payment received,
change order approved) with unread badges.
**Narration**: "Every commercial milestone — approvals, payments, change orders — raises an
in-app notification for the responsible team member automatically."

---

## Closing

"That's the full lifecycle: lead to BOQ, BOQ to priced proposal, proposal to signed contract,
contract to tracked payments, and controlled change orders along the way — all with role-based
permissions keeping internal costs and margins invisible to anyone who shouldn't see them."
