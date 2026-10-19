/**
 * Records the per-stage walkthrough videos shown in /help (docs/SIMPLIFICATION_REVIEW.md P4.2).
 *
 * For each UI language (Arabic, English) and each stage (quote, sign, build, finish) it drives the
 * real app with Playwright, writes `<stage>.<lang>.mp4`, a poster frame `<stage>.<lang>.jpg`, and
 * Arabic and English subtitles `<stage>.<lang>.<ar|en>.vtt` — each subtitle cue is timestamped at
 * the moment the matching action happens, so captions stay in sync if the UI gets faster or slower.
 *
 * Made for people who aren't used to software: every button pressed and field typed into is first
 * spotlighted (the rest of the screen dims, an amber frame circles it, a visible cursor glides to
 * it, a ripple shows the click), pacing is slow, and the browser runs at a smaller viewport that is
 * scaled up in the video so text and buttons read larger. Results worth noticing (the next-step
 * card, the WhatsApp button, the certificate) are spotlighted too, without clicking.
 *
 * There is no voice-over: the subtitles carry the narration. A narrated recording can replace any
 * `<stage>.<lang>.mp4` later (see components/guide/StageVideo.tsx).
 *
 * Usage (app stack running — backend + frontend; a fresh company is created per language via the
 * public signup API, nothing else is touched):
 *
 *   # from any directory that has playwright installed: npm i playwright@1 && npx playwright install chromium
 *   BASE=http://localhost:3000 API=http://localhost:8000/api/v1 \
 *     OUT=/path/to/repo/frontend/public/help/videos node /path/to/repo/demo/scripts/record_help_videos.mjs
 *
 * PAUSE (default 1.2) scales every pause; raise it for an even slower video.
 * Needs ffmpeg on PATH (webm -> mp4, and joining the two halves of the "sign" clip).
 */

import { createRequire } from "node:module";
import { execFileSync } from "node:child_process";
import { mkdirSync, mkdtempSync, renameSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const require = createRequire(join(process.cwd(), "/"));
const { chromium } = require("playwright");

const BASE = process.env.BASE ?? "http://localhost:3000";
const API = process.env.API ?? "http://localhost:8000/api/v1";
const OUT = process.env.OUT ?? "frontend/public/help/videos";
const LANGS = (process.env.LANGS ?? "ar,en").split(",");
/** Browser viewport, recorded 1:1 then scaled up to SIZE by ffmpeg — bigger text and buttons. */
const VIEW = { width: 1100, height: 688 };
const SIZE = { width: 1280, height: 800 };
const SCALE = `scale=${SIZE.width}:${SIZE.height}:flags=lanczos`;
const PAUSE = Number(process.env.PAUSE ?? 1.2);

mkdirSync(OUT, { recursive: true });
const RAW = mkdtempSync(join(tmpdir(), "help-videos-"));

// --- Words the script clicks/types, per UI language ---------------------------------------------
const L = {
  en: {
    next: "Next", apartment: "Apartment", create: "Create project", newVersion: "Create New Version",
    saveDraft: "Save Draft", send: "Send to Client", approve: "Approve proposal", confirmApprove: "Confirm approval",
    convert: "Convert to Contract", addSchedule: "Add Schedule", newCo: "New Change Order", createCo: "Create Draft", closeSnag: "Close Snag",
    recordHandover: "Record Handover", certificate: "Download Certificate", pricing: "Pricing", downPayment: "Down payment", vanity: "Marble vanity unit",
    reason: "The client asked for a marble vanity.", client: "John Doe",
    company: "Nile & Co. Interiors", snag: "Grout missing behind the kitchen sink", fixed: "Re-grouted and cleaned",
    scope: "Full finishing of the apartment at a standard specification.",
  },
  ar: {
    next: "التالي", apartment: "شقة", create: "إنشاء المشروع", newVersion: "إنشاء إصدار جديد",
    saveDraft: "حفظ المسودة", send: "إرسال إلى العميل", approve: "اعتمد العرض", confirmApprove: "تأكيد الاعتماد",
    convert: "تحويل إلى عقد", addSchedule: "إضافة جدول دفع", newCo: "أمر تغيير جديد", createCo: "إنشاء المسودة", closeSnag: "إغلاق الملاحظة",
    recordHandover: "تسجيل التسليم", certificate: "تنزيل الشهادة", pricing: "التسعير", downPayment: "مقدم", vanity: "وحدة حوض رخام",
    reason: "طلب العميل وحدة حوض رخام.", client: "محمد احمد",
    company: "نايل للتصميم الداخلي", snag: "ترويب ناقص خلف حوض المطبخ", fixed: "تم الترويب والتنظيف",
    scope: "تشطيب كامل للشقة بمواصفات عادية.",
  },
};

// --- Subtitles: the narration, in both languages -----------------------------------------------
const C = {
  dashboard: { en: "Start every job from Home: press “New Project”.", ar: "ابدأ كل مشروع من الرئيسية: اضغط \"مشروع جديد\"." },
  client: { en: "Pick the client — or type a new one with their mobile number.", ar: "اختر العميل — أو اكتب عميلًا جديدًا برقم موبايله." },
  job: { en: "Describe the job: the type of place and its area.", ar: "صِف المشروع: نوع المكان ومساحته." },
  template: { en: "Choose a ready template. Standard finishing is suggested.", ar: "اختر قالبًا جاهزًا. التشطيب العادي مقترح." },
  boq: { en: "The price list is filled in and priced for you. Edit any cell — it saves by itself.", ar: "قائمة الأسعار ممتلئة ومسعّرة. عدّل أي خانة — والحفظ تلقائي." },
  pricing: { en: "Totals update automatically. Add your profit as a pricing rule.", ar: "الإجماليات تتحدث تلقائيًا. أضف ربحك كقاعدة تسعير." },
  proposal: { en: "Create the proposal, write the scope, then send it.", ar: "أنشئ العرض، اكتب نطاق العمل، ثم أرسله." },
  whatsapp: { en: "One WhatsApp message: the client's project link and a 6-digit code.", ar: "رسالة واتساب واحدة: رابط مشروع العميل ورمز من 6 أرقام." },
  clientOpens: { en: "Your client opens their project page from WhatsApp — no app, no password.", ar: "عميلك يفتح صفحة مشروعه من واتساب — بدون تطبيق ولا كلمة مرور." },
  clientCode: { en: "They approve with their name and the 6-digit code.", ar: "يوافق باسمه والرمز المكوّن من 6 أرقام." },
  clientDone: { en: "Approved. You're notified straight away.", ar: "تم الاعتماد. ويصلك إشعار فورًا." },
  nextStep: { en: "Back in Fitout, the project tells you the next step.", ar: "في Fitout، يخبرك المشروع بالخطوة التالية." },
  convert: { en: "Convert the approved proposal to the contract — this is the signing.", ar: "حوّل العرض المعتمد إلى عقد — هذا هو التوقيع." },
  installments: { en: "Then add the installments: down payment, milestones, final payment.", ar: "ثم أضف الأقساط: المقدم والدفعات المرحلية والدفعة الأخيرة." },
  siteWork: { en: "During the work, keep tasks and site reports in Site Work.", ar: "أثناء التنفيذ، تابع المهام وتقارير الموقع في شغل الموقع." },
  extra: { en: "Client wants something extra? Create it under Extras & Changes.", ar: "العميل عاوز حاجة زيادة؟ أضفها في الإضافات والتعديلات." },
  extraSent: { en: "Send it like a proposal: the client approves it on their page with a code.", ar: "أرسلها مثل العرض: يوافق العميل عليها من صفحته برمز." },
  expenses: { en: "Log purchases and expenses — your real profit updates by itself.", ar: "سجّل المشتريات والمصروفات — ربحك الفعلي يتحدث تلقائيًا." },
  fixList: { en: "Before handover, list every defect in the Fix List.", ar: "قبل التسليم، سجّل كل عيب في قائمة الإصلاحات." },
  fixClosed: { en: "Close each fix when it's done.", ar: "أغلق كل إصلاح عند الانتهاء منه." },
  handover: { en: "When no mandatory fix is open, record the handover…", ar: "عندما لا يتبقى إصلاح إلزامي مفتوح، سجّل التسليم…" },
  certificate: { en: "…and download the handover certificate. Done!", ar: "…ونزّل شهادة التسليم. انتهى المشروع!" },
};

const sleep = (s) => new Promise((r) => setTimeout(r, s * 1000 * PAUSE));

async function api(token, org, method, path, body) {
  const headers = { Accept: "application/json", "Content-Type": "application/json" };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (org) headers["X-Organization-Id"] = String(org);
  const r = await fetch(`${API}${path}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(`${method} ${path} -> ${r.status} ${JSON.stringify(j).slice(0, 300)}`);
  return j.data ?? j;
}

// --- On-screen guidance layer: a visible cursor, a spotlight frame, and a click ripple ----------
// Injected into every page. Playwright's own mouse is invisible in recordings, so the cursor is a
// drawn element that follows real mousemove events; the spotlight is a fixed frame whose huge
// box-shadow dims everything else. All of it is pointer-events:none, so clicks pass through.
function guidanceLayer() {
  const install = () => {
    if (document.getElementById("__guide_cursor")) return;
    const style = document.createElement("style");
    style.textContent = `
      #__guide_cursor{position:fixed;left:0;top:0;width:30px;height:30px;z-index:2147483647;pointer-events:none;
        transform:translate(-4px,-2px);filter:drop-shadow(0 2px 3px rgba(0,0,0,.45));transition:opacity .2s}
      #__guide_spot{position:fixed;z-index:2147483646;pointer-events:none;border:4px solid #f59e0b;border-radius:12px;
        box-shadow:0 0 0 9999px rgba(15,15,15,.55),0 0 26px 6px rgba(245,158,11,.75);opacity:0;transition:opacity .35s, left .35s, top .35s, width .35s, height .35s}
      #__guide_ripple{position:fixed;z-index:2147483647;pointer-events:none;width:22px;height:22px;margin:-11px 0 0 -11px;
        border-radius:50%;border:4px solid #f59e0b;opacity:0}
      @keyframes __guide_pulse{0%{transform:scale(.4);opacity:1}100%{transform:scale(3.2);opacity:0}}`;
    document.head.appendChild(style);
    const cursor = document.createElement("div");
    cursor.id = "__guide_cursor";
    cursor.innerHTML = '<svg viewBox="0 0 24 24" width="30" height="30"><path d="M4 2l15 10-6.5 1.5L16 21l-3 1.4-3.6-7.6L4 19z" fill="#fff" stroke="#111" stroke-width="1.6" stroke-linejoin="round"/></svg>';
    const spot = document.createElement("div");
    spot.id = "__guide_spot";
    const ripple = document.createElement("div");
    ripple.id = "__guide_ripple";
    document.body.append(spot, ripple, cursor);
    document.addEventListener("mousemove", (e) => {
      cursor.style.left = `${e.clientX}px`;
      cursor.style.top = `${e.clientY}px`;
    }, true);
    window.__guide = {
      spot(b) {
        if (!b) { spot.style.opacity = "0"; return; }
        const pad = 8;
        Object.assign(spot.style, { left: `${b.x - pad}px`, top: `${b.y - pad}px`, width: `${b.width + pad * 2}px`, height: `${b.height + pad * 2}px`, opacity: "1" });
      },
      ripple(x, y) {
        Object.assign(ripple.style, { left: `${x}px`, top: `${y}px`, animation: "none" });
        void ripple.offsetWidth;
        ripple.style.animation = "__guide_pulse .7s ease-out";
      },
    };
  };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", install);
  else install();
}

/** Scroll the target to the middle, dim the rest, frame it, glide the cursor onto it. */
async function show(page, locator) {
  await locator.waitFor({ state: "visible", timeout: 30000 });
  await locator.evaluate((el) => el.scrollIntoView({ block: "center", inline: "center", behavior: "smooth" }));
  await sleep(0.6);
  const b = await locator.boundingBox();
  await page.evaluate((box) => window.__guide?.spot(box), b);
  await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, { steps: 30 });
  await sleep(0.9);
  return b;
}

const hide = (page) => page.evaluate(() => window.__guide?.spot(null));

/** Spotlight a result worth noticing, without clicking it. */
async function point(page, locator, seconds = 2.5) {
  await show(page, locator);
  await sleep(seconds);
  await hide(page);
  await sleep(0.5);
}

/** Spotlight a button/link, show the click, then press it. */
async function tap(page, locator) {
  const b = await show(page, locator);
  await page.evaluate(([x, y]) => window.__guide?.ripple(x, y), [b.x + b.width / 2, b.y + b.height / 2]);
  await sleep(0.4);
  await hide(page);
  await locator.click();
  await sleep(1.1);
}

/** Spotlight a field and type into it slowly. */
async function type(page, locator, text, delay = 95) {
  await show(page, locator);
  await locator.click();
  await locator.pressSequentially(text, { delay });
  await sleep(0.9);
  await hide(page);
  await sleep(0.5);
}

/** Spotlight a field and set it in one go (date inputs can't be typed character by character). */
async function set(page, locator, value) {
  await show(page, locator);
  await locator.fill(value);
  await sleep(1);
  await hide(page);
  await sleep(0.5);
}

/** One recorded browser context; cue() timestamps a subtitle relative to the recording start. */
async function recorder(browser, lang, auth) {
  const context = await browser.newContext({ viewport: VIEW, recordVideo: { dir: RAW, size: VIEW }, deviceScaleFactor: 1 });
  await context.addInitScript(([locale, token, org]) => {
    localStorage.setItem("idp.locale", locale);
    if (token) {
      localStorage.setItem("idp.auth.token", token);
      localStorage.setItem("idp.auth.organizationId", String(org));
    }
  }, [lang, auth?.token ?? null, auth?.org ?? null]);
  await context.addInitScript(guidanceLayer);
  const page = await context.newPage();
  page.on("dialog", (d) => d.accept());
  // Park the cursor mid-screen so it's visible from the first frame.
  await page.mouse.move(VIEW.width / 2, VIEW.height / 2);
  const t0 = Date.now();
  const cues = [];
  return {
    page,
    cue: (key) => cues.push({ t: (Date.now() - t0) / 1000, key }),
    async finish() {
      const video = page.video();
      await context.close();
      return { path: await video.path(), cues, duration: (Date.now() - t0) / 1000 };
    },
  };
}

function vtt(cues, duration, sub) {
  const ts = (s) => {
    const ms = Math.max(0, Math.round(s * 1000));
    const h = String(Math.floor(ms / 3600000)).padStart(2, "0");
    const m = String(Math.floor((ms % 3600000) / 60000)).padStart(2, "0");
    const sec = String(Math.floor((ms % 60000) / 1000)).padStart(2, "0");
    return `${h}:${m}:${sec}.${String(ms % 1000).padStart(3, "0")}`;
  };
  const lines = ["WEBVTT", ""];
  cues.forEach((c, i) => {
    const end = i + 1 < cues.length ? cues[i + 1].t : duration;
    lines.push(String(i + 1), `${ts(c.t)} --> ${ts(end - 0.05)}`, C[c.key][sub], "");
  });
  return lines.join("\n");
}

function probeDuration(file) {
  return Number(execFileSync("ffprobe", ["-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", file]).toString().trim());
}

/** webm part(s) -> one small H.264 mp4 + both subtitle files + a poster frame. */
function publish(stage, lang, parts) {
  const out = join(OUT, `${stage}.${lang}.mp4`);
  const encode = ["-c:v", "libx264", "-preset", "slow", "-crf", "30", "-pix_fmt", "yuv420p", "-movflags", "+faststart", "-an"];
  let cues = [];
  if (parts.length === 1) {
    execFileSync("ffmpeg", ["-y", "-loglevel", "error", "-i", parts[0].path, "-vf", SCALE, ...encode, out]);
    cues = parts[0].cues;
  } else {
    const inputs = parts.flatMap((p) => ["-i", p.path]);
    const filter = parts.map((_, i) => `[${i}:v]`).join("") + `concat=n=${parts.length}:v=1:a=0,${SCALE}[v]`;
    execFileSync("ffmpeg", ["-y", "-loglevel", "error", ...inputs, "-filter_complex", filter, "-map", "[v]", ...encode, out]);
    let offset = 0;
    for (const p of parts) {
      cues.push(...p.cues.map((c) => ({ ...c, t: c.t + offset })));
      offset += probeDuration(p.path);
    }
  }
  const duration = probeDuration(out);
  for (const sub of ["ar", "en"]) writeFileSync(join(OUT, `${stage}.${lang}.${sub}.vtt`), vtt(cues, duration, sub));
  // Poster frame so the player isn't a black box before it's pressed.
  execFileSync("ffmpeg", ["-y", "-loglevel", "error", "-ss", (duration * 0.6).toFixed(2), "-i", out, "-frames:v", "1", "-q:v", "5", join(OUT, `${stage}.${lang}.jpg`)]);
  console.log(`  ${stage}.${lang}.mp4  ${duration.toFixed(1)}s  ${cues.length} cues`);
}

async function recordLanguage(browser, lang) {
  const w = L[lang];
  const stamp = Date.now();
  const reg = await api(null, null, "POST", "/auth/register", {
    organization_name: w.company, name: lang === "ar" ? "أحمد" : "Ahmed",
    email: `help-video-${lang}-${stamp}@example.com`, password: "Password123!", password_confirmation: "Password123!",
  });
  const me = await api(reg.token, null, "GET", "/me");
  const auth = { token: reg.token, org: me.organizations[0].organization.id };
  const call = (m, p, b) => api(auth.token, auth.org, m, p, b);
  let projectId = null;
  const button = (p, name) => p.getByRole("button", { name, exact: true });
  const tab = (p, segment) => p.locator(`main nav a[href="/projects/${projectId}/${segment}"]`).first();

  // ---------------- quote ----------------
  let r = await recorder(browser, lang, auth);
  let p = r.page;
  await p.goto(`${BASE}/dashboard`, { waitUntil: "networkidle" });
  r.cue("dashboard");
  await sleep(2);
  await tap(p, p.locator('a[href="/projects/new"]').first());
  await p.waitForSelector("#new-client-name");
  r.cue("client");
  await type(p, p.locator("#new-client-name"), w.client);
  await type(p, p.locator("#new-client-phone"), "01001112233", 70);
  await tap(p, button(p, w.next));
  r.cue("job");
  await tap(p, p.getByRole("radio", { name: w.apartment }));
  await type(p, p.locator("#area"), "140", 160);
  await tap(p, button(p, w.next));
  await p.waitForSelector('input[name="template"]:checked');
  r.cue("template");
  await point(p, p.locator('label:has(input[name="template"]:checked)'), 2.5);
  await tap(p, button(p, w.create));
  await p.waitForURL(/\/boq$/, { timeout: 60000 });
  projectId = p.url().match(/projects\/(\d+)\/boq/)[1];
  await p.waitForLoadState("networkidle");
  r.cue("boq");
  await sleep(1.5);
  await point(p, p.locator("table").first(), 3);
  r.cue("pricing");
  await p.evaluate(() => window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" }));
  await sleep(2.5);
  await point(p, p.getByRole("heading", { name: w.pricing, exact: true }).locator("xpath=ancestor::div[contains(@class,'rounded-lg')][1]"), 3);
  await tap(p, tab(p, "proposal"));
  await tap(p, button(p, w.newVersion).first());
  await p.waitForSelector("#content-scope");
  r.cue("proposal");
  await type(p, p.locator("#content-scope"), w.scope, 45);
  await tap(p, button(p, w.saveDraft));
  await p.waitForFunction((label) => [...document.querySelectorAll("button")].some((b) => b.textContent === label && !b.disabled), w.send);
  await tap(p, button(p, w.send));
  const wa = p.locator('a[href^="https://wa.me/"]');
  await wa.waitFor();
  r.cue("whatsapp");
  await point(p, wa, 4);
  const message = decodeURIComponent((await wa.getAttribute("href")).split("text=")[1]);
  const portalUrl = message.match(/https?:\/\/\S+\/p\/client-portal\/\S+/)[0];
  const otp = message.match(/\b(\d{6})\b/)[1];
  publish("quote", lang, [await r.finish()]);

  // ---------------- sign (client half + staff half) ----------------
  r = await recorder(browser, lang, null);
  p = r.page;
  await p.goto(portalUrl.replace(/^https?:\/\/[^/]+/, BASE), { waitUntil: "networkidle" });
  r.cue("clientOpens");
  await sleep(2.5);
  await tap(p, button(p, w.approve));
  r.cue("clientCode");
  await type(p, p.locator("#decision-name"), w.client);
  await type(p, p.locator("#decision-otp"), otp, 220);
  await tap(p, button(p, w.confirmApprove));
  await p.getByRole("status").waitFor();
  r.cue("clientDone");
  await point(p, p.getByRole("status"), 3);
  const clientHalf = await r.finish();

  r = await recorder(browser, lang, auth);
  p = r.page;
  await p.goto(`${BASE}/projects/${projectId}`, { waitUntil: "networkidle" });
  await p.waitForSelector("#next-step-title");
  r.cue("nextStep");
  await point(p, p.locator("#next-step-title").locator("xpath=ancestor::section[1]"), 3);
  await tap(p, p.locator("#next-step-title").locator("xpath=ancestor::section[1]").getByRole("link"));
  await button(p, w.convert).waitFor();
  r.cue("convert");
  await tap(p, button(p, w.convert));
  await p.waitForLoadState("networkidle");
  await sleep(1.5);
  await tap(p, tab(p, "payments"));
  await tap(p, button(p, w.addSchedule).first());
  await p.waitForSelector("#schedule-name");
  r.cue("installments");
  await type(p, p.locator("#schedule-name"), w.downPayment);
  if (await p.locator("#schedule-percentage").count()) await type(p, p.locator("#schedule-percentage"), "30", 200);
  else await type(p, p.locator("#schedule-amount"), "100000", 120);
  await set(p, p.locator("#schedule-due-date"), new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10));
  await tap(p, p.locator("form").filter({ has: p.locator("#schedule-name") }).locator('button[type="submit"]'));
  await p.waitForLoadState("networkidle");
  await sleep(2.5);
  publish("sign", lang, [clientHalf, await r.finish()]);

  // ---------------- build ----------------
  r = await recorder(browser, lang, auth);
  p = r.page;
  await p.goto(`${BASE}/projects/${projectId}`, { waitUntil: "networkidle" });
  await sleep(1);
  await tap(p, tab(p, "execution"));
  r.cue("siteWork");
  await sleep(2.5);
  await tap(p, tab(p, "change-orders"));
  // With no change orders yet the editor is already open (and its button disabled).
  const newCo = button(p, w.newCo).first();
  await newCo.waitFor();
  if (await newCo.isEnabled()) await tap(p, newCo);
  await p.waitForSelector("#co-reason", { timeout: 20000 });
  r.cue("extra");
  await type(p, p.locator("#co-reason"), w.reason, 55);
  await type(p, p.locator('[id$="-desc"]').first(), w.vanity, 70);
  await type(p, p.locator('[id$="-qty"]').first(), "1", 150);
  await type(p, p.locator('[id$="-unit"]').first(), "pcs", 120);
  await type(p, p.locator('[id$="-new"]').first(), "7500", 150);
  await tap(p, button(p, w.createCo));
  await button(p, w.send).waitFor();
  await tap(p, button(p, w.send));
  const coWa = p.locator('a[href^="https://wa.me/"]');
  await coWa.waitFor();
  r.cue("extraSent");
  await point(p, coWa, 4);
  await tap(p, tab(p, "expenses"));
  await p.waitForLoadState("networkidle");
  r.cue("expenses");
  await sleep(3.5);
  publish("build", lang, [await r.finish()]);

  // ---------------- finish ----------------
  await call("POST", `/projects/${projectId}/snags`, { description: w.snag, priority: "high", is_mandatory: true });
  r = await recorder(browser, lang, auth);
  p = r.page;
  await p.goto(`${BASE}/projects/${projectId}`, { waitUntil: "networkidle" });
  await sleep(1);
  await tap(p, tab(p, "snagging"));
  await p.waitForLoadState("networkidle");
  r.cue("fixList");
  await sleep(1.5);
  await type(p, p.locator("input[placeholder]").last(), w.fixed, 70);
  await tap(p, button(p, w.closeSnag));
  await p.waitForLoadState("networkidle");
  r.cue("fixClosed");
  await sleep(2.5);
  await tap(p, tab(p, "handover"));
  await button(p, w.recordHandover).waitFor();
  r.cue("handover");
  await tap(p, button(p, w.recordHandover));
  await p.waitForLoadState("networkidle");
  r.cue("certificate");
  await point(p, button(p, w.certificate), 3.5);
  publish("finish", lang, [await r.finish()]);
}

const browser = await chromium.launch();
try {
  for (const lang of LANGS) {
    console.log(`Recording (${lang})…`);
    await recordLanguage(browser, lang);
  }
} finally {
  await browser.close();
}
renameSync(RAW, `${RAW}-done`);
console.log(`Done → ${OUT}`);
