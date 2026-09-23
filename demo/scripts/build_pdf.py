#!/usr/bin/env python3
"""Builds the Interior Design Platform demo PDF from the recorded stills + script."""
from pathlib import Path
from PIL import Image
from reportlab.lib.pagesizes import letter
from reportlab.lib.units import inch
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, PageBreak, Image as RLImage,
    Table, TableStyle, HRFlowable, KeepTogether,
)
from reportlab.lib.enums import TA_CENTER

DEMO_DIR = Path("/home/agtaweel/Documents/Mine/Claude/interior-design-platform/demo")
STILLS = DEMO_DIR / "stills"
OUT = DEMO_DIR / "Interior_Design_Platform_Demo.pdf"

INK = colors.HexColor("#18181b")
MUTED = colors.HexColor("#71717a")
ACCENT = colors.HexColor("#2563eb")
RULE = colors.HexColor("#e4e4e7")

styles = getSampleStyleSheet()
styles.add(ParagraphStyle("TitleBig", parent=styles["Title"], fontSize=28, leading=34, textColor=INK))
styles.add(ParagraphStyle("Subtitle", parent=styles["Normal"], fontSize=13, leading=18, textColor=MUTED, alignment=TA_CENTER))
styles.add(ParagraphStyle("SectionNum", parent=styles["Normal"], fontSize=10, leading=12, textColor=ACCENT, fontName="Helvetica-Bold"))
styles.add(ParagraphStyle("SectionTitle", parent=styles["Heading1"], fontSize=18, leading=22, textColor=INK, spaceBefore=2, spaceAfter=6))
styles.add(ParagraphStyle("Body", parent=styles["Normal"], fontSize=10.5, leading=15.5, textColor=INK, spaceAfter=8))
styles.add(ParagraphStyle("Caption", parent=styles["Normal"], fontSize=8.5, leading=11, textColor=MUTED, spaceBefore=4))
styles.add(ParagraphStyle("Cover", parent=styles["Normal"], fontSize=10.5, leading=16, textColor=MUTED, alignment=TA_CENTER))

PAGE_W, PAGE_H = letter
MAX_IMG_W = PAGE_W - 1.6 * inch


def fitted_image(path, max_w=MAX_IMG_W, max_h=4.6 * inch):
    with Image.open(path) as im:
        w, h = im.size
    ratio = min(max_w / w, max_h / h)
    return RLImage(str(path), width=w * ratio, height=h * ratio)


def rule():
    return HRFlowable(width="100%", thickness=0.75, color=RULE, spaceBefore=4, spaceAfter=14)


def section(num, title, body, images, caption=None):
    flow = [
        Paragraph(f"STEP {num:02d}", styles["SectionNum"]),
        Paragraph(title, styles["SectionTitle"]),
        Paragraph(body, styles["Body"]),
    ]
    for img_name in images:
        flow.append(Spacer(1, 4))
        flow.append(fitted_image(STILLS / img_name))
    if caption:
        flow.append(Paragraph(caption, styles["Caption"]))
    flow.append(Spacer(1, 10))
    flow.append(rule())
    return flow


story = []

# ---- Cover page ----
story.append(Spacer(1, 1.6 * inch))
story.append(Paragraph("Interior Design & Finishing<br/>Management Platform", styles["TitleBig"]))
story.append(Spacer(1, 14))
story.append(Paragraph(
    "Product Demo — from first BOQ line to a client-approved change order",
    styles["Subtitle"],
))
story.append(Spacer(1, 40))
story.append(HRFlowable(width="40%", thickness=1.2, color=ACCENT, hAlign="CENTER"))
story.append(Spacer(1, 40))
story.append(Paragraph(
    "Scenario: <b>Nile &amp; Co. Interiors</b>, a Cairo-based design studio, running the "
    "<b>Zamalek Penthouse Renovation</b> for client Laila Farouk — from a priced bill of "
    "quantities through a sent proposal, client approval, signed contract, recorded payment, "
    "and an approved mid-project change order.",
    styles["Cover"],
))
story.append(Spacer(1, 80))
story.append(Paragraph(
    "A companion screen-recording (walkthrough.mp4) captures this same flow as video.",
    styles["Cover"],
))
story.append(PageBreak())

# ---- Sections ----
story += section(
    1, "Sign in",
    "This is the Interior Design &amp; Finishing Management Platform — a multi-tenant system "
    "for design studios to run a project from first quote through to final handover, priced in "
    "EGP, with Arabic and English support throughout.",
    ["01_login.png"],
)
story += section(
    2, "Dashboard",
    "The dashboard gives an owner a one-screen view of active projects, pending proposals, and "
    "receivables — the daily operating picture for the studio.",
    ["02_dashboard.png"],
)
story += section(
    3, "Client Profile",
    "Every client has a single view: contact details, their properties, and every project run "
    "for them. Laila Farouk's profile shows her Zamalek apartment and the renovation project "
    "linked to it.",
    ["03_client_profile.png"],
)
story += section(
    4, "Project Overview",
    "The project starts empty on the commercial side — value, collected, and outstanding all "
    "populate automatically as the project moves through the workflow that follows.",
    ["04_project_overview.png"],
)
story += section(
    5, "BOQ Builder",
    "The Bill of Quantities is a full spreadsheet-style editor — categories (Flooring, "
    "Painting, Electrical, Kitchen), rooms (Living Room, Kitchen, Master Bedroom), and "
    "per-line costs versus client-facing pricing, calculated live as you type.",
    ["05_boq_builder.png"],
)
story += section(
    6, "Pricing Panel",
    "Markup and fee rules stack on top of the BOQ automatically — here, a 12% contractor "
    "markup on direct cost and a 10% design &amp; supervision fee on the client subtotal. "
    "Internally, every layer of the math is visible; toggling to the client preview shows "
    "that a client only ever sees the bottom line, never the cost breakdown or margin.",
    ["06_pricing_breakdown.png", "07_pricing_client_preview.png"],
    caption="Internal breakdown (top) versus the client-facing preview (bottom) — no cost or "
            "margin data is ever rendered in client mode.",
)
story += section(
    7, "Proposal — Author and Send",
    "Sending a proposal snapshots the BOQ and pricing into an immutable version, and generates "
    "a secure link with a one-time verification code — the two things staff copy and share with "
    "the client manually, since this MVP has no built-in messaging integration.",
    ["08_proposal_editor.png", "09_proposal_sent_link_otp.png"],
)
story += section(
    8, "Client Proposal Portal (public)",
    "This is what the client actually sees when they open the link on their phone — scope, "
    "priced line items, and a final total, with zero internal cost data anywhere on the page. "
    "Approval requires the one-time code the studio shared separately.",
    ["10_public_proposal_portal.png", "11_public_proposal_approve_form.png", "12_public_proposal_approved.png"],
)
story += section(
    9, "Contract",
    "Once approved, converting to a contract is one click. The contract value is permanently "
    "locked to what the client actually approved — it is never silently recalculated behind "
    "their back.",
    ["13_contract.png"],
)
story += section(
    10, "Payments",
    "Payment schedules and receipts are tracked per project. Here, a 50% deposit schedule is "
    "created and a matching payment is recorded against it — partial payments are supported, "
    "and every receipt is stored and retrievable.",
    ["14_payments_add_schedule.png", "15_payments_schedule_created.png",
     "16_payments_record_form.png", "17_payments_recorded.png"],
)
story += section(
    11, "Change Order — Request, Approve, Apply",
    "Scope changes after the contract is signed go through a controlled, auditable change "
    "order: drafted internally, sent to the client with its own secure link and code, approved "
    "the same way as the original proposal, and finally applied — the only path by which a "
    "signed contract's value is ever allowed to move.",
    ["18_change_order_editor.png", "19_change_order_sent.png",
     "20_public_change_order_portal.png", "21_public_change_order_approved.png",
     "22_change_order_applied.png"],
    caption="Applying the change order updates both the BOQ (a new line item appears) and the "
            "contract value in one transaction — the increase matches the change order's price "
            "impact exactly.",
)
story += section(
    12, "Reports",
    "Reports roll every project up into one organization-wide view — revenue collected, what's "
    "still outstanding, and estimated margin, clearly labeled as an estimate since it is "
    "derived from BOQ pricing rather than real expense tracking. Note that the Change Order "
    "Value and Budget Variance columns match exactly — the only way a contract's value moves "
    "after signing is through an applied, audited change order.",
    ["23_reports.png"],
)
story += section(
    13, "Notifications",
    "Every commercial milestone — a proposal approval, a payment received, a contract created, "
    "a change order approved — raises an in-app notification for the responsible team member "
    "automatically, with no manual follow-up required.",
    ["24_notifications.png"],
)

# ---- Closing ----
story.append(Spacer(1, 0.4 * inch))
story.append(Paragraph("Summary", styles["SectionTitle"]))
story.append(Paragraph(
    "That is the full lifecycle demonstrated end-to-end: lead to priced BOQ, BOQ to a sent and "
    "client-approved proposal, proposal to a signed contract, contract to tracked payments, and "
    "a controlled, client-approved change order along the way — all while role-based "
    "permissions keep internal costs and margins invisible to anyone who should not see them.",
    styles["Body"],
))

doc = SimpleDocTemplate(
    str(OUT), pagesize=letter,
    leftMargin=0.8 * inch, rightMargin=0.8 * inch,
    topMargin=0.8 * inch, bottomMargin=0.8 * inch,
    title="Interior Design Platform — Product Demo",
    author="Nile & Co. Interiors (demo)",
)
doc.build(story)
print(f"PDF written: {OUT}")
