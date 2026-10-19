/**
 * Long-form copy for the two user guides: the public one (/guide, read before signing up) and the
 * in-app one (/help, for signed-in staff). Kept out of dictionaries.ts on purpose — that file is
 * for short UI labels; this is paragraphs. Every string carries both languages side by side so a
 * missing translation is a type error, same guarantee the dictionary gives.
 *
 * Button names quoted here must match the live labels in dictionaries.ts — if a label changes
 * there, update it here too.
 */

import type { Locale } from "@/lib/i18n/dictionaries";

export interface Localized {
  en: string;
  ar: string;
}

export const pick = (text: Localized, locale: Locale) => text[locale];

/** Checks each entry against T (excess properties included) and widens the array to T[]. */
const typed = <T,>(items: T[]): T[] => items;

export interface GuideStep {
  title: Localized;
  body: Localized;
  /** Where in the app this happens, e.g. "Project → Items & Prices". */
  where?: Localized;
  /** In-app link for the /help guide; omitted when the step lives inside a specific project. */
  href?: string;
  tip?: Localized;
}

export interface GuideStage {
  id: string;
  title: Localized;
  summary: Localized;
  steps: GuideStep[];
}

export interface GlossaryEntry {
  term: Localized;
  meaning: Localized;
}

export interface FaqEntry {
  q: Localized;
  a: Localized;
}

/* ------------------------------------------------------------------------------------------ */
/* Shared                                                                                      */
/* ------------------------------------------------------------------------------------------ */

export const GLOSSARY: GlossaryEntry[] = [
  {
    term: { en: "Items & Prices (BOQ)", ar: "البنود والأسعار (BOQ)" },
    meaning: {
      en: "The list of every item of work in the job — tiles, paint, electrics — with how much of each and what it costs. It's your price list for this one project.",
      ar: "قائمة بكل بنود الشغل في المشروع — سيراميك، دهانات، كهرباء — بالكمية والتكلفة لكل بند. هي قائمة الأسعار الخاصة بهذا المشروع.",
    },
  },
  {
    term: { en: "Template", ar: "القالب" },
    meaning: {
      en: "A ready-made BOQ (for example \"2-bedroom apartment, standard finish\") you copy into a project so you don't start from an empty table.",
      ar: "قائمة كميات جاهزة (مثل \"شقة غرفتين، تشطيب عادي\") تنسخها في المشروع حتى لا تبدأ من جدول فارغ.",
    },
  },
  {
    term: { en: "Pricing rule", ar: "قاعدة التسعير" },
    meaning: {
      en: "Your profit, fees or discount added on top of the cost. The client only ever sees the final price — never your cost or margin.",
      ar: "هامش ربحك أو الرسوم أو الخصم فوق التكلفة. العميل يرى السعر النهائي فقط — لا يرى التكلفة ولا هامشك أبدًا.",
    },
  },
  {
    term: { en: "Proposal", ar: "العرض" },
    meaning: {
      en: "The quotation you send the client: your price plus scope, timeline and terms. Each time you change it you create a new version; old versions are kept.",
      ar: "عرض السعر الذي ترسله للعميل: السعر مع نطاق العمل والجدول الزمني والشروط. كل تعديل يُنشئ إصدارًا جديدًا، والإصدارات القديمة محفوظة.",
    },
  },
  {
    term: { en: "OTP code", ar: "رمز التحقق (OTP)" },
    meaning: {
      en: "A 6-digit code the client types to approve. It proves the approval really came from them — like the code your bank sends you.",
      ar: "رمز من 6 أرقام يكتبه العميل عند الموافقة. يثبت أن الموافقة جاءت منه فعلًا — مثل الرمز الذي يرسله لك البنك.",
    },
  },
  {
    term: { en: "Extras & changes (change order)", ar: "الإضافات والتعديلات (أمر تغيير)" },
    meaning: {
      en: "Extra work (or removed work) after the contract is signed. The client approves it first, then it updates the contract value.",
      ar: "شغل إضافي (أو شغل ملغى) بعد توقيع العقد. يوافق عليه العميل أولًا، ثم تتحدث قيمة العقد.",
    },
  },
  {
    term: { en: "Fix list (snags)", ar: "قائمة الإصلاحات (Snags)" },
    meaning: {
      en: "A defect to fix before handing over — a cracked tile, a door that doesn't close. A \"mandatory\" snag blocks handover until it's closed.",
      ar: "عيب يجب إصلاحه قبل التسليم — بلاطة مشروخة أو باب لا يُغلق. الملاحظة \"الإلزامية\" تمنع التسليم حتى تُغلق.",
    },
  },
  {
    term: { en: "Client portal", ar: "بوابة العميل" },
    meaning: {
      en: "One private link per project where the client follows progress, proposals, payments and photos from their phone — no app, no password.",
      ar: "رابط خاص لكل مشروع يتابع منه العميل التقدم والعروض والمدفوعات والصور من موبايله — بدون تطبيق وبدون كلمة مرور.",
    },
  },
];

/* ------------------------------------------------------------------------------------------ */
/* Public guide (/guide) — for someone who hasn't signed up yet                                */
/* ------------------------------------------------------------------------------------------ */

export const PUBLIC_GUIDE = {
  eyebrow: { en: "How Fitout works", ar: "كيف يعمل Fitout" },
  title: {
    en: "From the first call to the final handover — in one place",
    ar: "من أول مكالمة حتى تسليم المفتاح — في مكان واحد",
  },
  intro: {
    en: "Fitout is for interior design and finishing companies. It replaces the Excel price sheets, the WhatsApp screenshots and the paper receipts with one simple system your whole team and your clients can follow.",
    ar: "Fitout مخصص لشركات التصميم الداخلي والتشطيبات. يستبدل ملفات الإكسل والصور على واتساب والإيصالات الورقية بنظام واحد بسيط يتابعه فريقك وعملاؤك.",
  },
  ctaPrimary: { en: "Create your company account", ar: "أنشئ حساب شركتك" },
  ctaSecondary: { en: "I already have an account", ar: "لدي حساب بالفعل" },

  journeyTitle: { en: "The journey of one project", ar: "رحلة مشروع واحد" },
  journey: [
    {
      title: { en: "Add the client", ar: "أضف العميل" },
      body: {
        en: "Name and phone number. Their apartment or villa details are optional.",
        ar: "الاسم ورقم الموبايل. بيانات الشقة أو الفيلا اختيارية.",
      },
    },
    {
      title: { en: "Price the work", ar: "سعّر الشغل" },
      body: {
        en: "Start from a ready template, adjust quantities and costs. Fitout calculates the totals and adds your profit.",
        ar: "ابدأ من قالب جاهز وعدّل الكميات والتكاليف. Fitout يحسب الإجماليات ويضيف ربحك.",
      },
    },
    {
      title: { en: "Send the proposal", ar: "أرسل العرض" },
      body: {
        en: "A clean quotation the client opens on their phone from a WhatsApp link — and approves with a code.",
        ar: "عرض سعر واضح يفتحه العميل على موبايله من رابط على واتساب — ويوافق عليه برمز.",
      },
    },
    {
      title: { en: "Sign and collect", ar: "وقّع وحصّل" },
      body: {
        en: "The approved proposal becomes the contract. Set the installments and record each payment with its receipt.",
        ar: "العرض المعتمد يصبح العقد. حدّد الأقساط وسجّل كل دفعة بإيصالها.",
      },
    },
    {
      title: { en: "Build and hand over", ar: "نفّذ وسلّم" },
      body: {
        en: "Track site work, purchases and extras, fix the last defects, then issue a handover certificate.",
        ar: "تابع شغل الموقع والمشتريات والإضافات، أصلح آخر العيوب، ثم أصدر شهادة التسليم.",
      },
    },
  ],

  benefitsTitle: { en: "Why companies switch", ar: "لماذا تنتقل الشركات إلى Fitout" },
  benefits: [
    {
      title: { en: "Quotes in minutes, not days", ar: "عروض أسعار في دقائق، لا أيام" },
      body: {
        en: "Templates and a built-in catalog mean you rarely type an item from scratch.",
        ar: "القوالب وكتالوج البنود الجاهز يعنيان أنك نادرًا ما تكتب بندًا من الصفر.",
      },
    },
    {
      title: { en: "Your margin stays private", ar: "هامش ربحك يبقى سرًا" },
      body: {
        en: "Clients and site staff see prices, never your costs or profit.",
        ar: "العملاء وطاقم الموقع يرون الأسعار فقط، لا التكاليف ولا الأرباح.",
      },
    },
    {
      title: { en: "No more \"I never agreed to that\"", ar: "لا مزيد من \"أنا ما وافقتش على كده\"" },
      body: {
        en: "Every approval, extra and payment is recorded with who, what and when.",
        ar: "كل موافقة وإضافة ودفعة مسجلة: مَن ومتى وماذا.",
      },
    },
    {
      title: { en: "Know who still owes you", ar: "اعرف مَن لا يزال مدينًا لك" },
      body: {
        en: "The dashboard shows what's collected and what's left on every project.",
        ar: "لوحة الرئيسية تعرض المحصَّل والمتبقي في كل مشروع.",
      },
    },
  ],

  clientTitle: { en: "What your client sees", ar: "ماذا يرى عميلك" },
  clientBody: {
    en: "Your client never creates an account or installs anything. You send them a link on WhatsApp; they open it on their phone and see their proposal, contract, payments and progress photos. To approve, they type their name and the 6-digit code you sent them.",
    ar: "عميلك لا يُنشئ حسابًا ولا يثبّت أي تطبيق. ترسل له رابطًا على واتساب فيفتحه على موبايله ويرى العرض والعقد والمدفوعات وصور التقدم. للموافقة يكتب اسمه والرمز المكوّن من 6 أرقام الذي أرسلته له.",
  },

  startTitle: { en: "Your first 15 minutes", ar: "أول 15 دقيقة لك" },
  start: [
    {
      en: "Create your company account (company name, your name, email, password).",
      ar: "أنشئ حساب شركتك (اسم الشركة، اسمك، البريد، كلمة المرور).",
    },
    {
      en: "Follow the \"Getting started\" checklist on your home screen.",
      ar: "اتبع قائمة \"البداية\" التي تظهر في الصفحة الرئيسية.",
    },
    {
      en: "Add one real client and price one real job from a template.",
      ar: "أضف عميلًا حقيقيًا واحدًا وسعّر مشروعًا حقيقيًا من قالب.",
    },
    {
      en: "Send yourself the proposal link to see exactly what your client will see.",
      ar: "أرسل رابط العرض لنفسك لترى بالضبط ما سيراه عميلك.",
    },
  ],

  glossaryTitle: { en: "Words you'll see", ar: "كلمات ستقابلها" },

  faqTitle: { en: "Common questions", ar: "أسئلة شائعة" },
  faq: typed<FaqEntry>([
    {
      q: { en: "Do I need to be good with computers?", ar: "هل أحتاج أن أكون محترفًا في الكمبيوتر؟" },
      a: {
        en: "No. If you can use WhatsApp and fill a simple form, you can use Fitout. Every screen has one main button that tells you the next step.",
        ar: "لا. إذا كنت تستخدم واتساب وتملأ نموذجًا بسيطًا، فتستطيع استخدام Fitout. كل شاشة فيها زر رئيسي يخبرك بالخطوة التالية.",
      },
    },
    {
      q: { en: "Does it work in Arabic?", ar: "هل يعمل باللغة العربية؟" },
      a: {
        en: "Yes — the whole app works in Arabic and English. Switch any time from the language menu.",
        ar: "نعم — التطبيق كله يعمل بالعربية والإنجليزية. غيّر اللغة في أي وقت من قائمة اللغة.",
      },
    },
    {
      q: { en: "Can I use it on my phone?", ar: "هل أستطيع استخدامه على الموبايل؟" },
      a: {
        en: "Yes. It works in the phone's browser — nothing to install. Building a long price list is easier on a laptop or tablet.",
        ar: "نعم. يعمل من متصفح الموبايل — بدون تثبيت. إعداد قائمة أسعار طويلة أسهل على اللابتوب أو التابلت.",
      },
    },
    {
      q: { en: "Can my team use it too?", ar: "هل يستطيع فريقي استخدامه؟" },
      a: {
        en: "Yes. Invite designers, project managers and site staff. Each role sees only what they need — for example, site staff can't see your profit.",
        ar: "نعم. ادعُ المصممين ومديري المشاريع وطاقم الموقع. كل دور يرى ما يحتاجه فقط — مثلًا طاقم الموقع لا يرى أرباحك.",
      },
    },
    {
      q: { en: "Does Fitout collect money from my clients?", ar: "هل Fitout يحصّل الفلوس من عملائي؟" },
      a: {
        en: "Not yet. Your client pays you the way they do today (transfer, cash, cheque); you record each payment and attach the receipt so everything stays tracked.",
        ar: "ليس بعد. عميلك يدفع لك بنفس الطريقة المعتادة (تحويل، كاش، شيك)، وأنت تسجّل كل دفعة وترفق الإيصال ليبقى كل شيء متابَعًا.",
      },
    },
    {
      q: { en: "Is my data safe?", ar: "هل بياناتي آمنة؟" },
      a: {
        en: "Each company's data is completely separate. Client links can be cancelled at any time, and every change is logged.",
        ar: "بيانات كل شركة منفصلة تمامًا. يمكن إلغاء روابط العملاء في أي وقت، وكل تعديل مسجّل.",
      },
    },
  ]),

  closingTitle: { en: "Ready to try it on a real job?", ar: "جاهز تجرّبه على مشروع حقيقي؟" },
};

/* ------------------------------------------------------------------------------------------ */
/* In-app guide (/help) — for signed-in staff                                                  */
/* ------------------------------------------------------------------------------------------ */

export const APP_GUIDE = {
  title: { en: "Help & guide", ar: "المساعدة والدليل" },
  intro: {
    en: "Every project goes through the same five stages. Find the stage you're at and follow the steps — each one names the exact button to press.",
    ar: "كل مشروع يمر بنفس المراحل الخمس. ابحث عن المرحلة التي وصلت إليها واتبع الخطوات — كل خطوة تذكر اسم الزر الذي تضغطه بالضبط.",
  },
  onThisPage: { en: "Stages", ar: "المراحل" },
  stepLabel: { en: "Step", ar: "خطوة" },
  whereLabel: { en: "Where", ar: "أين" },
  tipLabel: { en: "Tip", ar: "نصيحة" },
  openLabel: { en: "Open", ar: "افتح" },
  glossaryTitle: { en: "Words you'll see", ar: "كلمات ستقابلها" },
  clientTitle: { en: "What your client sees", ar: "ماذا يرى عميلك" },
  clientPoints: [
    {
      en: "One portal link per project (Project → Overview → Generate Link). Send it once; it shows proposals, contract, payments, change orders and photos.",
      ar: "رابط بوابة واحد لكل مشروع (المشروع ← نظرة عامة ← إنشاء رابط). أرسله مرة واحدة؛ يعرض العروض والعقد والمدفوعات وأوامر التغيير والصور.",
    },
    {
      en: "To approve a proposal or a change order, the client uses the separate link and 6-digit code you get when you press \"Send to Client\".",
      ar: "للموافقة على عرض أو أمر تغيير، يستخدم العميل الرابط المنفصل والرمز المكوّن من 6 أرقام الذي يظهر لك عند الضغط على \"إرسال إلى العميل\".",
    },
    {
      en: "The client never sees your costs, suppliers or profit — only final prices.",
      ar: "العميل لا يرى أبدًا تكاليفك أو مورديك أو أرباحك — فقط الأسعار النهائية.",
    },
    {
      en: "If a link leaks, cancel it (\"Revoke\") and generate a new one.",
      ar: "إذا تسرّب رابط، ألغِه (\"إلغاء الرابط\") وأنشئ رابطًا جديدًا.",
    },
  ],
  stillStuckTitle: { en: "Still stuck?", ar: "لا تزال متوقفًا؟" },
  stillStuckBody: {
    en: "Ask your company's owner or admin — they can change your role if a button you need is missing. Missing buttons are usually a permissions setting, not a bug.",
    ar: "اسأل مالك الشركة أو المسؤول — يمكنهم تغيير دورك إذا كان زر تحتاجه غير ظاهر. الأزرار المختفية غالبًا سببها الصلاحيات، وليست خطأ.",
  },

  stages: typed<GuideStage>([
    {
      id: "setup",
      title: { en: "0 · Set up your company (once)", ar: "٠ · جهّز شركتك (مرة واحدة)" },
      summary: {
        en: "Ten minutes, done once. Makes every proposal look like yours.",
        ar: "عشر دقائق، مرة واحدة فقط. تجعل كل عرض يحمل هوية شركتك.",
      },
      steps: [
        {
          title: { en: "Fill in your company details", ar: "أكمل بيانات الشركة" },
          body: {
            en: "Name, legal name, logo, phone and email. These appear on proposals and contracts.",
            ar: "الاسم والاسم القانوني والشعار والهاتف والبريد. تظهر على العروض والعقود.",
          },
          where: { en: "Settings → Company Profile", ar: "الإعدادات ← بيانات الشركة" },
          href: "/settings",
        },
        {
          title: { en: "Invite your team", ar: "ادعُ فريقك" },
          body: {
            en: "Press \"Invite Member\", enter their email and pick a role. Designers can't see money; site staff can only view.",
            ar: "اضغط \"دعوة عضو\" واكتب البريد واختر الدور. المصمم لا يرى الأرقام المالية، وطاقم الموقع للاطّلاع فقط.",
          },
          where: { en: "Settings → Members & Roles", ar: "الإعدادات ← الأعضاء والأدوار" },
          href: "/settings",
        },
        {
          title: { en: "Check your templates", ar: "راجع القوالب" },
          body: {
            en: "Ready-made BOQs for common jobs. Adjust the prices to your own once, and every new quote starts from them.",
            ar: "قوائم كميات جاهزة للأعمال الشائعة. عدّل الأسعار لتناسبك مرة واحدة، وكل عرض جديد يبدأ منها.",
          },
          where: { en: "Settings → BOQ templates", ar: "الإعدادات ← قوالب الكميات" },
          href: "/settings/boq-templates",
        },
      ],
    },
    {
      id: "quote",
      title: { en: "1 · Quote the job", ar: "١ · سعّر المشروع" },
      summary: {
        en: "From a phone call to a proposal on the client's phone.",
        ar: "من مكالمة تليفون إلى عرض سعر على موبايل العميل.",
      },
      steps: [
        {
          title: { en: "Add the client", ar: "أضف العميل" },
          body: {
            en: "Press \"New Client\" — only the name is required; add the phone so you can WhatsApp them. Not sure they'll buy yet? Add them as a lead instead and convert later.",
            ar: "اضغط \"عميل جديد\" — الاسم فقط مطلوب، وأضف الموبايل لتراسله على واتساب. غير متأكد أنه سيتعاقد؟ أضفه كعميل محتمل وحوّله لاحقًا.",
          },
          where: { en: "Clients (or Leads)", ar: "العملاء (أو العملاء المحتملون)" },
          href: "/clients",
        },
        {
          title: { en: "Create the project", ar: "أنشئ المشروع" },
          body: {
            en: "Press \"New Project\", choose the client, give it a name like \"Ahmed — Zayed apartment\". Dates are optional.",
            ar: "اضغط \"مشروع جديد\"، اختر العميل، وسمّه مثلًا \"أحمد — شقة زايد\". التواريخ اختيارية.",
          },
          where: { en: "Projects", ar: "المشاريع" },
          href: "/projects?new=1",
          tip: {
            en: "The client must exist first — add them in Clients, then come back.",
            ar: "يجب أن يكون العميل موجودًا أولًا — أضفه من العملاء ثم ارجع.",
          },
        },
        {
          title: { en: "Fill the BOQ from a template", ar: "املأ الكميات من قالب" },
          body: {
            en: "Open the project, go to \"Items & Prices\" and press \"Apply Templates\". Then just change quantities and costs — click any cell, type, and it saves by itself.",
            ar: "افتح المشروع، ادخل \"البنود والأسعار\" واضغط \"تطبيق القوالب\". ثم غيّر الكميات والتكاليف فقط — اضغط على أي خانة واكتب، والحفظ تلقائي.",
          },
          where: { en: "Project → Items & Prices", ar: "المشروع ← البنود والأسعار" },
          tip: {
            en: "Have it in Excel already? Save it as CSV and use \"Import CSV\".",
            ar: "عندك الملف على إكسل؟ احفظه بصيغة CSV واستخدم \"استيراد CSV\".",
          },
        },
        {
          title: { en: "Add your profit", ar: "أضف ربحك" },
          body: {
            en: "In the pricing panel under the table, press \"Add Rule\" (e.g. Markup 20%). Totals update by themselves. Use the client preview to see exactly the price they'll see.",
            ar: "في لوحة التسعير أسفل الجدول، اضغط \"إضافة قاعدة\" (مثلًا هامش 20٪). الإجماليات تتحدث تلقائيًا. استخدم معاينة العميل لترى السعر الذي سيظهر له بالضبط.",
          },
          where: { en: "Project → Items & Prices", ar: "المشروع ← البنود والأسعار" },
          tip: {
            en: "Changed items after writing the proposal? Just reopen the draft — it picks up the new prices.",
            ar: "عدّلت البنود بعد كتابة العرض؟ افتح المسودة من جديد — ستأخذ الأسعار الجديدة تلقائيًا.",
          },
        },
        {
          title: { en: "Write and send the proposal", ar: "اكتب العرض وأرسله" },
          body: {
            en: "Press \"Create New Version\", fill the scope, timeline and payment plan, press \"Save Draft\", then \"Send to Client\". Copy the link and the 6-digit code and send both to the client on WhatsApp.",
            ar: "اضغط \"إنشاء إصدار جديد\"، اكتب نطاق العمل والجدول الزمني وخطة الدفع، اضغط \"حفظ المسودة\" ثم \"إرسال إلى العميل\". انسخ الرابط والرمز المكوّن من 6 أرقام وأرسلهما للعميل على واتساب.",
          },
          where: { en: "Project → Proposal", ar: "المشروع ← العرض" },
          tip: {
            en: "The link and code are shown only once — copy them before closing the box.",
            ar: "الرابط والرمز يظهران مرة واحدة فقط — انسخهما قبل إغلاق النافذة.",
          },
        },
      ],
    },
    {
      id: "sign",
      title: { en: "2 · Sign & set payments", ar: "٢ · وقّع وحدّد الدفعات" },
      summary: {
        en: "The client approved — lock the deal and plan the installments.",
        ar: "العميل وافق — ثبّت الاتفاق وخطّط الأقساط.",
      },
      steps: [
        {
          title: { en: "Wait for the client's answer", ar: "انتظر رد العميل" },
          body: {
            en: "The proposal changes to \"Approved\" or \"Changes Requested\". For changes, press \"Create New Version\", edit, and send again.",
            ar: "تتغير حالة العرض إلى \"معتمد\" أو \"طُلب تعديل\". في حالة التعديل اضغط \"إنشاء إصدار جديد\"، عدّل، وأرسل مرة أخرى.",
          },
          where: { en: "Project → Proposal", ar: "المشروع ← العرض" },
        },
        {
          title: { en: "Turn it into the contract", ar: "حوّله إلى عقد" },
          body: {
            en: "Press \"Convert to Contract\". This is the signing — there's no separate step. Download the PDF if you want a printed copy.",
            ar: "اضغط \"تحويل إلى عقد\". هذا هو التوقيع نفسه — لا توجد خطوة منفصلة. نزّل ملف PDF إذا أردت نسخة مطبوعة.",
          },
          where: { en: "Project → Contract", ar: "المشروع ← العقد" },
        },
        {
          title: { en: "Set the installments", ar: "حدّد الأقساط" },
          body: {
            en: "Press \"Add Schedule\" for each installment: name (e.g. \"Down payment\"), due date and amount or percentage.",
            ar: "اضغط \"إضافة جدول دفع\" لكل قسط: الاسم (مثل \"مقدم\")، تاريخ الاستحقاق، والمبلغ أو النسبة.",
          },
          where: { en: "Project → Payments", ar: "المشروع ← المدفوعات" },
        },
        {
          title: { en: "Record each payment", ar: "سجّل كل دفعة" },
          body: {
            en: "When money arrives, press \"Manage\" next to the installment, then record the payment and attach a photo of the receipt.",
            ar: "عند وصول الفلوس، اضغط \"إدارة\" بجانب القسط ثم سجّل الدفعة وأرفق صورة الإيصال.",
          },
          where: { en: "Project → Payments", ar: "المشروع ← المدفوعات" },
        },
        {
          title: { en: "Share the client portal", ar: "شارك بوابة العميل" },
          body: {
            en: "Press \"Generate Link\" and send it once. The client can follow everything from now on without asking you.",
            ar: "اضغط \"إنشاء رابط\" وأرسله مرة واحدة. يستطيع العميل متابعة كل شيء من الآن دون أن يسألك.",
          },
          where: { en: "Project → Overview", ar: "المشروع ← نظرة عامة" },
        },
      ],
    },
    {
      id: "build",
      title: { en: "3 · Do the work", ar: "٣ · نفّذ الشغل" },
      summary: {
        en: "Keep the site, the purchases and any extras under control.",
        ar: "خلّي الموقع والمشتريات وأي إضافات تحت السيطرة.",
      },
      steps: [
        {
          title: { en: "Plan tasks and post site reports", ar: "خطّط المهام وانشر تقارير الموقع" },
          body: {
            en: "Add tasks and move them from \"To Do\" to \"Done\". After each site visit, add a short report with photos — it can be downloaded as a PDF.",
            ar: "أضف المهام وانقلها من \"قيد الانتظار\" إلى \"مكتملة\". بعد كل زيارة للموقع، أضف تقريرًا قصيرًا بالصور — يمكن تنزيله PDF.",
          },
          where: { en: "Project → Site Work", ar: "المشروع ← شغل الموقع" },
        },
        {
          title: { en: "Track purchases and expenses", ar: "تابع المشتريات والمصروفات" },
          body: {
            en: "Create purchase orders for suppliers and record what was delivered. Log any other spend as an expense. Your real profit updates automatically.",
            ar: "أنشئ أوامر شراء للموردين وسجّل ما تم استلامه. سجّل أي مصروف آخر كمصروف. ربحك الفعلي يتحدث تلقائيًا.",
          },
          where: { en: "Project → Expenses", ar: "المشروع ← المصروفات" },
        },
        {
          title: { en: "Client wants something extra?", ar: "العميل عاوز حاجة زيادة؟" },
          body: {
            en: "Create a change order, add the new or removed items and send it like a proposal. After the client approves, press \"Apply to Items & Contract\" — only then does the contract value change.",
            ar: "أنشئ أمر تغيير، أضف البنود الجديدة أو الملغاة وأرسله مثل العرض. بعد موافقة العميل اضغط \"تطبيق على البنود والعقد\" — وقتها فقط تتغير قيمة العقد.",
          },
          where: { en: "Project → Extras & Changes", ar: "المشروع ← الإضافات والتعديلات" },
          tip: {
            en: "Never agree to extras verbally — a change order is your proof.",
            ar: "لا توافق على إضافات شفهيًا — أمر التغيير هو إثباتك.",
          },
        },
        {
          title: { en: "Upload designs and photos", ar: "ارفع التصاميم والصور" },
          body: {
            en: "Upload designs, progress photos and final photos. The client sees them in their portal gallery.",
            ar: "ارفع التصاميم وصور سير العمل والصور النهائية. يراها العميل في معرض البوابة.",
          },
          where: { en: "Project → Documents", ar: "المشروع ← المستندات" },
        },
      ],
    },
    {
      id: "finish",
      title: { en: "4 · Finish & hand over", ar: "٤ · أنهِ وسلّم" },
      summary: {
        en: "Close the last defects and give the client their certificate.",
        ar: "أغلق آخر العيوب وسلّم العميل شهادته.",
      },
      steps: [
        {
          title: { en: "Walk the site and list defects", ar: "امشِ في الموقع وسجّل العيوب" },
          body: {
            en: "Add each defect as a snag. Tick \"Mandatory\" for anything that must be fixed before handover. Close each one when it's done.",
            ar: "أضف كل عيب كملاحظة. علّم \"إلزامي\" على أي شيء يجب إصلاحه قبل التسليم. أغلق كل ملاحظة عند إصلاحها.",
          },
          where: { en: "Project → Fix List", ar: "المشروع ← قائمة الإصلاحات" },
        },
        {
          title: { en: "Record the handover", ar: "سجّل التسليم" },
          body: {
            en: "Enter the handover date and warranty period, then download the handover certificate. It won't let you hand over while a mandatory snag is open.",
            ar: "أدخل تاريخ التسليم ومدة الضمان، ثم نزّل شهادة التسليم. لن يسمح لك بالتسليم طالما هناك ملاحظة إلزامية مفتوحة.",
          },
          where: { en: "Project → Handover", ar: "المشروع ← التسليم" },
        },
        {
          title: { en: "Check how the job did", ar: "راجع نتيجة المشروع" },
          body: {
            en: "Reports show each project's contract value, collected, outstanding and margin. Export to CSV for your accountant.",
            ar: "التقارير تعرض لكل مشروع قيمة العقد والمحصَّل والمتبقي والهامش. صدّرها CSV لمحاسبك.",
          },
          where: { en: "Reports", ar: "التقارير" },
          href: "/reports",
        },
      ],
    },
  ]),
};

/* ------------------------------------------------------------------------------------------ */
/* Dashboard "Getting started" checklist                                                       */
/* ------------------------------------------------------------------------------------------ */

export const GETTING_STARTED = {
  title: { en: "Getting started", ar: "البداية" },
  intro: {
    en: "Four steps to your first proposal. We tick them off as you go.",
    ar: "أربع خطوات حتى أول عرض سعر. نعلّمها تلقائيًا كلما أنجزت واحدة.",
  },
  progress: { en: "{done} of {total} done", ar: "تم {done} من {total}" },
  dismiss: { en: "Hide", ar: "إخفاء" },
  doneLabel: { en: "done", ar: "تم" },
  fullGuide: { en: "Read the full guide", ar: "اقرأ الدليل كاملًا" },
  steps: {
    client: {
      title: { en: "Add your first client", ar: "أضف أول عميل" },
      body: { en: "Just a name and phone number.", ar: "اسم ورقم موبايل فقط." },
      cta: { en: "Add client", ar: "أضف عميلًا" },
    },
    project: {
      title: { en: "Create a project for them", ar: "أنشئ مشروعًا له" },
      body: { en: "Pick the client and give the job a name.", ar: "اختر العميل وسمِّ المشروع." },
      cta: { en: "New project", ar: "مشروع جديد" },
    },
    price: {
      title: { en: "Price the work", ar: "سعّر الشغل" },
      body: {
        en: "In the project, open \"Items & Prices\" and apply a template — the total fills in by itself.",
        ar: "داخل المشروع افتح \"البنود والأسعار\" وطبّق قالبًا — يظهر الإجمالي تلقائيًا.",
      },
      cta: { en: "Open project", ar: "افتح المشروع" },
    },
    send: {
      title: { en: "Send the proposal", ar: "أرسل العرض" },
      body: {
        en: "Open \"Proposal\", create a version and press \"Send to Client\".",
        ar: "افتح \"العرض\"، أنشئ إصدارًا واضغط \"إرسال إلى العميل\".",
      },
      cta: { en: "Open proposal", ar: "افتح العرض" },
    },
  },
};
