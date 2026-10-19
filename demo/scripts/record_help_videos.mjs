/**
 * Records the per-stage walkthrough videos shown in /help (docs/SIMPLIFICATION_REVIEW.md P4.2).
 *
 * For each UI language (Arabic, English) and each stage (quote, sign, build, finish) it drives the
 * real app with Playwright, writes `<stage>.<lang>.mp4`, a poster frame `<stage>.<lang>.jpg`, and
 * Arabic and English subtitles `<stage>.<lang>.<ar|en>.vtt` — each subtitle cue is timestamped at the moment the matching action
 * happens, so captions stay in sync if the UI gets faster or slower.
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
const SIZE = { width: 1280, height: 800 };
const PAUSE = Number(process.env.PAUSE ?? 1);

mkdirSync(OUT, { recursive: true });
const RAW = mkdtempSync(join(tmpdir(), "help-videos-"));

// --- Words the script clicks, per UI language -------------------------------------------------
const L = {
  en: {
    next: "Next", apartment: "Apartment", create: "Create project", newVersion: "Create New Version",
    saveDraft: "Save Draft", send: "Send to Client", approve: "Approve proposal", confirmApprove: "Confirm approval",
    convert: "Convert to Contract", addSchedule: "Add Schedule", newCo: "New Change Order", createCo: "Create Draft", closeSnag: "Close Snag",
    recordHandover: "Record Handover", downPayment: "Down payment", vanity: "Marble vanity unit",
    reason: "The client asked for a marble vanity in the master bathroom.", client: "Mona Adel",
    company: "Nile & Co. Interiors", snag: "Grout missing behind the kitchen sink", fixed: "Re-grouted and cleaned",
    scope: "Full finishing of the apartment at a standard specification: plaster, flooring, paint, electrical, plumbing, doors, kitchen and bathrooms.",
  },
  ar: {
    next: "التالي", apartment: "شقة", create: "إنشاء المشروع", newVersion: "إنشاء إصدار جديد",
    saveDraft: "حفظ المسودة", send: "إرسال إلى العميل", approve: "اعتمد العرض", confirmApprove: "تأكيد الاعتماد",
    convert: "تحويل إلى عقد", addSchedule: "إضافة جدول دفع", newCo: "أمر تغيير جديد", createCo: "إنشاء المسودة", closeSnag: "إغلاق الملاحظة",
    recordHandover: "تسجيل التسليم", downPayment: "مقدم", vanity: "وحدة حوض رخام",
    reason: "طلب العميل وحدة حوض رخام في الحمام الرئيسي.", client: "منى عادل",
    company: "نايل للتصميم الداخلي", snag: "ترويب ناقص خلف حوض المطبخ", fixed: "تم الترويب والتنظيف",
    scope: "تشطيب كامل للشقة بمواصفات عادية: محارة وأرضيات ودهانات وكهرباء وسباكة وأبواب ومطبخ وحمامات.",
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

/** One recorded browser context; cue() timestamps a subtitle relative to the recording start. */
async function recorder(browser, lang, auth) {
  const context = await browser.newContext({ viewport: SIZE, recordVideo: { dir: RAW, size: SIZE }, deviceScaleFactor: 1 });
  await context.addInitScript(([locale, token, org]) => {
    localStorage.setItem("idp.locale", locale);
    if (token) {
      localStorage.setItem("idp.auth.token", token);
      localStorage.setItem("idp.auth.organizationId", String(org));
    }
  }, [lang, auth?.token ?? null, auth?.org ?? null]);
  const page = await context.newPage();
  page.on("dialog", (d) => d.accept());
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

/** webm part(s) -> one small H.264 mp4 + both subtitle files. */
function publish(stage, lang, parts) {
  const out = join(OUT, `${stage}.${lang}.mp4`);
  const encode = ["-c:v", "libx264", "-preset", "slow", "-crf", "30", "-pix_fmt", "yuv420p", "-movflags", "+faststart", "-an"];
  let cues = [];
  if (parts.length === 1) {
    execFileSync("ffmpeg", ["-y", "-loglevel", "error", "-i", parts[0].path, ...encode, out]);
    cues = parts[0].cues;
  } else {
    const inputs = parts.flatMap((p) => ["-i", p.path]);
    const filter = parts.map((_, i) => `[${i}:v]`).join("") + `concat=n=${parts.length}:v=1:a=0[v]`;
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

  // ---------------- quote ----------------
  let r = await recorder(browser, lang, auth);
  let p = r.page;
  await p.goto(`${BASE}/dashboard`, { waitUntil: "networkidle" });
  r.cue("dashboard");
  await sleep(2.5);
  await p.locator('a[href="/projects/new"]').first().click();
  await p.waitForSelector("#new-client-name");
  r.cue("client");
  await p.locator("#new-client-name").pressSequentially(w.client, { delay: 60 });
  await p.locator("#new-client-phone").pressSequentially("01001112233", { delay: 40 });
  await sleep(1);
  await p.getByRole("button", { name: w.next }).click();
  r.cue("job");
  await sleep(0.8);
  await p.getByRole("radio", { name: w.apartment }).click();
  await p.locator("#area").pressSequentially("140", { delay: 120 });
  await sleep(1.5);
  await p.getByRole("button", { name: w.next }).click();
  await p.waitForSelector('input[name="template"]:checked');
  r.cue("template");
  await sleep(3);
  await p.getByRole("button", { name: w.create }).click();
  await p.waitForURL(/\/boq$/, { timeout: 60000 });
  const projectId = p.url().match(/projects\/(\d+)\/boq/)[1];
  await p.waitForLoadState("networkidle");
  r.cue("boq");
  await sleep(3);
  await p.mouse.wheel(0, 900);
  await sleep(1.5);
  await p.mouse.wheel(0, 2400);
  r.cue("pricing");
  await sleep(1);
  await p.keyboard.press("End");
  await sleep(3);
  await p.locator(`a[href="/projects/${projectId}/proposal"]`).click();
  await p.getByRole("button", { name: w.newVersion }).first().click();
  await p.waitForSelector("#content-scope");
  r.cue("proposal");
  await p.locator("#content-scope").pressSequentially(w.scope, { delay: 12 });
  await sleep(0.8);
  await p.getByRole("button", { name: w.saveDraft }).click();
  await p.getByRole("button", { name: w.send }).waitFor();
  await p.waitForFunction((label) => [...document.querySelectorAll("button")].some((b) => b.textContent === label && !b.disabled), w.send);
  await p.getByRole("button", { name: w.send }).click();
  const wa = p.locator('a[href^="https://wa.me/"]');
  await wa.waitFor();
  await wa.scrollIntoViewIfNeeded();
  r.cue("whatsapp");
  await sleep(4);
  const message = decodeURIComponent((await wa.getAttribute("href")).split("text=")[1]);
  const portalUrl = message.match(/https?:\/\/\S+\/p\/client-portal\/\S+/)[0];
  const otp = message.match(/\b(\d{6})\b/)[1];
  publish("quote", lang, [await r.finish()]);

  // ---------------- sign (client half + staff half) ----------------
  r = await recorder(browser, lang, null);
  p = r.page;
  await p.goto(portalUrl.replace(/^https?:\/\/[^/]+/, BASE), { waitUntil: "networkidle" });
  r.cue("clientOpens");
  await sleep(3);
  await p.getByRole("button", { name: w.approve }).click();
  r.cue("clientCode");
  await p.locator("#decision-name").pressSequentially(w.client, { delay: 60 });
  await p.locator("#decision-otp").pressSequentially(otp, { delay: 150 });
  await sleep(1);
  await p.getByRole("button", { name: w.confirmApprove }).click();
  await p.getByRole("status").waitFor();
  r.cue("clientDone");
  await sleep(3);
  const clientHalf = await r.finish();

  r = await recorder(browser, lang, auth);
  p = r.page;
  await p.goto(`${BASE}/projects/${projectId}`, { waitUntil: "networkidle" });
  await p.waitForSelector("#next-step-title");
  r.cue("nextStep");
  await sleep(3);
  await p.locator(`a[href="/projects/${projectId}/contract"]`).last().click();
  await p.getByRole("button", { name: w.convert }).waitFor();
  r.cue("convert");
  await sleep(1.5);
  await p.getByRole("button", { name: w.convert }).click();
  await p.waitForLoadState("networkidle");
  await sleep(2.5);
  await p.locator(`a[href="/projects/${projectId}/payments"]`).first().click();
  await p.getByRole("button", { name: w.addSchedule }).first().click();
  await p.waitForSelector("#schedule-name");
  r.cue("installments");
  await p.locator("#schedule-name").pressSequentially(w.downPayment, { delay: 70 });
  if (await p.locator("#schedule-percentage").count()) await p.locator("#schedule-percentage").fill("30");
  else await p.locator("#schedule-amount").fill("100000");
  await p.locator("#schedule-due-date").fill(new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10));
  await sleep(1);
  await p.locator("form").filter({ has: p.locator("#schedule-name") }).locator('button[type="submit"]').click();
  await p.waitForLoadState("networkidle");
  await sleep(3);
  publish("sign", lang, [clientHalf, await r.finish()]);

  // ---------------- build ----------------
  r = await recorder(browser, lang, auth);
  p = r.page;
  await p.goto(`${BASE}/projects/${projectId}/execution`, { waitUntil: "networkidle" });
  r.cue("siteWork");
  await sleep(3);
  await p.locator(`a[href="/projects/${projectId}/change-orders"]`).click();
  // With no change orders yet the editor is already open (and its button disabled).
  const newCo = p.getByRole("button", { name: w.newCo }).first();
  await newCo.waitFor();
  if (await newCo.isEnabled()) await newCo.click();
  await p.waitForSelector("#co-reason", { timeout: 20000 });
  r.cue("extra");
  await p.locator("#co-reason").pressSequentially(w.reason, { delay: 25 });
  await p.locator('[id$="-desc"]').first().pressSequentially(w.vanity, { delay: 40 });
  await p.locator('[id$="-qty"]').first().fill("1");
  await p.locator('[id$="-unit"]').first().fill("pcs");
  await p.locator('[id$="-new"]').first().fill("7500");
  await sleep(1.5);
  await p.getByRole("button", { name: w.createCo }).click();
  await p.getByRole("button", { name: w.send }).waitFor();
  await sleep(1);
  await p.getByRole("button", { name: w.send }).click();
  const coWa = p.locator('a[href^="https://wa.me/"]');
  await coWa.waitFor();
  await coWa.scrollIntoViewIfNeeded();
  r.cue("extraSent");
  await sleep(4);
  await p.locator(`a[href="/projects/${projectId}/expenses"]`).click();
  await p.waitForLoadState("networkidle");
  r.cue("expenses");
  await sleep(3.5);
  publish("build", lang, [await r.finish()]);

  // ---------------- finish ----------------
  await call("POST", `/projects/${projectId}/snags`, { description: w.snag, priority: "high", is_mandatory: true });
  r = await recorder(browser, lang, auth);
  p = r.page;
  await p.goto(`${BASE}/projects/${projectId}/snagging`, { waitUntil: "networkidle" });
  r.cue("fixList");
  await sleep(3);
  await p.locator("input[placeholder]").last().pressSequentially(w.fixed, { delay: 50 });
  await p.getByRole("button", { name: w.closeSnag }).click();
  await p.waitForLoadState("networkidle");
  r.cue("fixClosed");
  await sleep(2.5);
  await p.locator(`a[href="/projects/${projectId}/handover"]`).click();
  await p.getByRole("button", { name: w.recordHandover }).waitFor();
  r.cue("handover");
  await sleep(2.5);
  await p.getByRole("button", { name: w.recordHandover }).click();
  await p.waitForLoadState("networkidle");
  await sleep(1);
  r.cue("certificate");
  await sleep(3.5);
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
