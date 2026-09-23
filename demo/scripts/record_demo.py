#!/usr/bin/env python3
"""
Records a Playwright walkthrough of the Interior Design Platform demo scenario
(Nile & Co. Interiors) as an MP4 video, and captures PNG stills at each major
step for the companion PDF.
"""
import os
import re
import time
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = "http://localhost:3000"
DEMO_DIR = Path("/home/agtaweel/Documents/Mine/Claude/interior-design-platform/demo")
STILLS_DIR = DEMO_DIR / "stills"
VIDEO_TMP = Path("/tmp/claude_demo_video")
PROJECT_ID = 63

EMAIL = "sara@nileandco.example"
PASSWORD = "password"

STILLS_DIR.mkdir(parents=True, exist_ok=True)
VIDEO_TMP.mkdir(parents=True, exist_ok=True)
shutil.rmtree(VIDEO_TMP, ignore_errors=True)
VIDEO_TMP.mkdir(parents=True, exist_ok=True)

shot_counter = [0]


def shot(page, name):
    shot_counter[0] += 1
    path = STILLS_DIR / f"{shot_counter[0]:02d}_{name}.png"
    page.screenshot(path=str(path))
    print(f"  📸 {path.name}")


def pause(page, ms=1200):
    page.wait_for_timeout(ms)


def first_visible(locator, timeout_ms=10000):
    """Both the Approve and Request-Changes forms on these public pages render a
    same-labeled 'Your name' field, one of them hidden (not display:none-removed, just
    CSS-hidden) — .first can land on the hidden one. Poll all matches for the first
    that's actually visible."""
    deadline = time.time() + timeout_ms / 1000
    while time.time() < deadline:
        count = locator.count()
        for i in range(count):
            el = locator.nth(i)
            if el.is_visible():
                return el
        time.sleep(0.2)
    raise TimeoutError("no visible match found for locator")


def click_reveal(page, button, label_regex, name="reveal"):
    """Click a toggle button that reveals a form. Single click only (retrying risks
    re-clicking a toggle closed) — waits generously, and dumps a debug screenshot if the
    expected labeled field never appears visibly."""
    button.scroll_into_view_if_needed()
    button.click()
    try:
        return first_visible(page.get_by_label(label_regex), timeout_ms=10000)
    except Exception:
        debug_path = STILLS_DIR / f"_debug_{name}.png"
        page.screenshot(path=str(debug_path))
        print(f"    ⚠️  visible label not found after click, debug screenshot: {debug_path}")
        raise


def find_value_by_pattern(page, pattern):
    """Grab the value of the first <input> on the page whose value matches pattern."""
    inputs = page.locator("input").all()
    for inp in inputs:
        try:
            val = inp.input_value()
        except Exception:
            continue
        if val and re.search(pattern, val):
            return val
    return None


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(
        viewport={"width": 1440, "height": 900},
        record_video_dir=str(VIDEO_TMP),
        record_video_size={"width": 1440, "height": 900},
    )
    page = context.new_page()
    page.set_default_timeout(15000)

    # ---- 1. Login ----
    print("1. Login")
    page.goto(f"{BASE}/login", wait_until="domcontentloaded")
    pause(page, 1200)
    page.get_by_label("Email").fill(EMAIL)
    page.get_by_label("Password").fill(PASSWORD)
    shot(page, "login")
    page.get_by_role("button", name="Sign in").click()
    page.wait_for_url("**/dashboard", timeout=15000)
    pause(page, 1500)

    # ---- 2. Dashboard ----
    print("2. Dashboard")
    shot(page, "dashboard")
    pause(page, 1000)

    # ---- 3. Client profile ----
    print("3. Client profile")
    page.get_by_role("link", name="Clients").click()
    page.wait_for_load_state("domcontentloaded")
    pause(page, 1200)
    page.get_by_text("Laila Farouk").first.click()
    page.wait_for_load_state("domcontentloaded")
    pause(page, 1200)
    shot(page, "client_profile")

    # ---- 4. Project overview ----
    print("4. Project overview")
    page.get_by_text("Zamalek Penthouse Renovation").first.click()
    page.wait_for_load_state("domcontentloaded")
    pause(page, 1200)
    shot(page, "project_overview")

    # ---- 5. BOQ Builder ----
    print("5. BOQ Builder")
    page.goto(f"{BASE}/projects/{PROJECT_ID}/boq", wait_until="domcontentloaded")
    pause(page, 1500)
    shot(page, "boq_builder")

    # ---- 6. Pricing panel ----
    print("6. Pricing panel")
    page.get_by_role("button", name=re.compile("Recalculate", re.I)).click()
    pause(page, 1500)
    shot(page, "pricing_breakdown")
    client_preview_btn = page.get_by_role("button", name=re.compile("Client preview", re.I))
    if client_preview_btn.count():
        client_preview_btn.first.click()
        pause(page, 1000)
        shot(page, "pricing_client_preview")
        page.get_by_role("button", name=re.compile("Internal view", re.I)).first.click()
        pause(page, 1200)

    # ---- 7. Proposal — author and send ----
    print("7. Proposal — create and send")
    page.goto(f"{BASE}/projects/{PROJECT_ID}/proposal", wait_until="domcontentloaded")
    pause(page, 1200)
    new_version_btn = page.get_by_role("button", name=re.compile("Create New Version|New Change Order|Create Draft", re.I))
    if new_version_btn.count():
        new_version_btn.first.click()
        pause(page, 1200)

    cover = page.get_by_label(re.compile("Cover Note", re.I))
    if cover.count():
        cover.fill("A refined full renovation of the Zamalek penthouse — flooring, "
                    "electrical, and a custom kitchen build, delivered over 16 weeks.")
    scope = page.get_by_label(re.compile("Scope of Work", re.I))
    if scope.count():
        scope.fill("Full flooring replacement, feature wall painting, recessed lighting "
                    "throughout, and a custom-built kitchen with quartz countertops.")
    shot(page, "proposal_editor")

    save_btn = page.get_by_role("button", name=re.compile("^Save Draft$", re.I))
    if save_btn.count():
        save_btn.first.click()
        pause(page, 1200)

    send_btn = page.get_by_role("button", name=re.compile("Send to Client", re.I))
    send_btn.first.click()
    pause(page, 1500)
    shot(page, "proposal_sent_link_otp")

    proposal_link = find_value_by_pattern(page, r"/p/proposals/")
    proposal_otp = find_value_by_pattern(page, r"^\d{6}$")
    print(f"   proposal_link={proposal_link} otp={proposal_otp}")

    # ---- 8. Client Proposal Portal (public) ----
    print("8. Public proposal portal — approve")
    page.goto(proposal_link, wait_until="domcontentloaded")
    pause(page, 1500)
    shot(page, "public_proposal_portal")

    approve_btn = page.get_by_role("button", name=re.compile("Approve Proposal", re.I))
    approve_btn.first.scroll_into_view_if_needed()
    approve_btn.first.click()
    name_field = page.get_by_placeholder("Full name")
    name_field.wait_for(state="visible", timeout=10000)
    name_field.fill("Laila Farouk")
    otp_field = page.get_by_placeholder("000000")
    otp_field.fill(proposal_otp)
    shot(page, "public_proposal_approve_form")
    page.get_by_role("button", name=re.compile("Confirm Approval", re.I)).click()
    pause(page, 1500)
    shot(page, "public_proposal_approved")

    # ---- 9. Contract ----
    print("9. Contract")
    page.goto(f"{BASE}/projects/{PROJECT_ID}/contract", wait_until="domcontentloaded")
    pause(page, 1200)
    convert_btn = page.get_by_role("button", name=re.compile("Convert to Contract", re.I))
    if convert_btn.count():
        convert_btn.first.click()
        pause(page, 1500)
    shot(page, "contract")

    # ---- 10. Payments ----
    print("10. Payments")
    page.goto(f"{BASE}/projects/{PROJECT_ID}/payments", wait_until="domcontentloaded")
    pause(page, 1200)
    add_schedule_btn = page.get_by_role("button", name=re.compile("Add Schedule", re.I))
    add_schedule_btn.first.click()
    pause(page, 1200)
    page.get_by_label(re.compile("^Name$", re.I)).fill("Deposit")
    due_date = page.get_by_label(re.compile("Due Date", re.I))
    due_date.fill("2026-11-01")
    pct_btn = page.get_by_role("button", name=re.compile("% of Contract Value", re.I))
    if pct_btn.count():
        pct_btn.first.click()
    page.get_by_label(re.compile("Percentage", re.I)).fill("50")
    shot(page, "payments_add_schedule")
    page.get_by_role("button", name=re.compile("^Add Schedule$", re.I)).last.click()
    pause(page, 1200)
    shot(page, "payments_schedule_created")

    manage_btns = page.get_by_role("button", name=re.compile("Manage", re.I))
    if manage_btns.count():
        manage_btns.first.click()
        pause(page, 1200)
        amount_field = page.get_by_label(re.compile("Amount", re.I)).first
        amount_field.fill("64678.30")
        method = page.get_by_label(re.compile("Payment Method", re.I))
        if method.count():
            method.select_option(index=0)
        date_paid = page.get_by_label(re.compile("Date Paid|Paid At", re.I))
        if date_paid.count():
            date_paid.fill("2026-09-23")
        shot(page, "payments_record_form")
        record_btn = page.get_by_role("button", name=re.compile("Record Payment", re.I))
        record_btn.first.click()
        pause(page, 1200)
        shot(page, "payments_recorded")

    # ---- 11. Change Order ----
    print("11. Change Order")
    page.goto(f"{BASE}/projects/{PROJECT_ID}/change-orders", wait_until="domcontentloaded")
    pause(page, 1200)
    # The "New Change Order" editor is already open by default when no change orders
    # exist yet (same pattern as the Proposal editor) — the button itself is correctly
    # disabled in that state, so only click it if it's actually enabled.
    new_co_btn = page.get_by_role("button", name=re.compile("^New Change Order$", re.I))
    if new_co_btn.count() and new_co_btn.first.is_enabled():
        new_co_btn.first.click()
        pause(page, 1200)
    page.get_by_label(re.compile("Why is this change needed|Reason", re.I)).fill(
        "Client requested additional recessed lighting in the kitchen island area."
    )
    # Item 1 already exists by default in "Add new item" mode — fill it directly rather
    # than clicking "Add Item" (which would create a second, empty, invalid item).
    page.get_by_label(re.compile("^Description$", re.I)).first.fill("Island recessed LED downlight")
    page.get_by_label(re.compile("^Unit$", re.I)).first.fill("pcs")
    page.get_by_label(re.compile("^Quantity$", re.I)).first.fill("4")
    page.get_by_label(re.compile("New Unit Price", re.I)).first.fill("220")
    shot(page, "change_order_editor")

    create_draft_btn = page.get_by_role("button", name=re.compile("Create Draft", re.I))
    create_draft_btn.first.click()
    pause(page, 1200)

    co_send_btn = page.get_by_role("button", name=re.compile("Send to Client", re.I))
    co_send_btn.first.click()
    pause(page, 1500)
    shot(page, "change_order_sent")

    co_link = find_value_by_pattern(page, r"/p/change-orders/")
    co_otp = find_value_by_pattern(page, r"^\d{6}$")
    print(f"   co_link={co_link} otp={co_otp}")

    print("11b. Public change-order approval")
    page.goto(co_link, wait_until="domcontentloaded")
    pause(page, 1200)
    shot(page, "public_change_order_portal")
    co_approve_btn = page.get_by_role("button", name=re.compile("Approve Change", re.I))
    co_approve_btn.first.scroll_into_view_if_needed()
    co_approve_btn.first.click()
    co_name_field = page.get_by_placeholder("Full name")
    co_name_field.wait_for(state="visible", timeout=10000)
    co_name_field.fill("Laila Farouk")
    page.get_by_placeholder("000000").fill(co_otp)
    page.get_by_role("button", name=re.compile("Confirm Approval", re.I)).click()
    pause(page, 1500)
    shot(page, "public_change_order_approved")

    print("11c. Apply change order")
    page.goto(f"{BASE}/projects/{PROJECT_ID}/change-orders", wait_until="domcontentloaded")
    pause(page, 1200)
    apply_btn = page.get_by_role("button", name=re.compile("Apply to BOQ", re.I))
    if apply_btn.count():
        apply_btn.first.click()
        pause(page, 1500)
    shot(page, "change_order_applied")

    # ---- 12. Reports ----
    print("12. Reports")
    page.goto(f"{BASE}/reports", wait_until="domcontentloaded")
    pause(page, 1500)
    shot(page, "reports")

    # ---- 13. Notifications ----
    print("13. Notifications")
    bell = page.locator("button").filter(has=page.locator("svg")).first
    # Try a more targeted approach: look for a button near the nav with an unread badge / bell icon
    try:
        page.get_by_role("button", name=re.compile("notification", re.I)).first.click()
    except Exception:
        bell.click()
    pause(page, 1200)
    shot(page, "notifications")

    pause(page, 1000)
    context.close()
    browser.close()

print("\nConverting recording to MP4...")
webm_files = list(VIDEO_TMP.glob("*.webm"))
if webm_files:
    webm = str(webm_files[0])
    final_mp4 = str(DEMO_DIR / "walkthrough.mp4")
    result = subprocess.run([
        "ffmpeg", "-y", "-i", webm,
        "-c:v", "libx264", "-preset", "fast", "-crf", "20",
        "-movflags", "+faststart",
        final_mp4
    ], capture_output=True, text=True)
    if result.returncode == 0:
        size_mb = os.path.getsize(final_mp4) / (1024 * 1024)
        print(f"✅ Video saved: {final_mp4} ({size_mb:.1f} MB)")
    else:
        print("ffmpeg failed:", result.stderr[-2000:])
else:
    print("⚠️  No webm recording found")
