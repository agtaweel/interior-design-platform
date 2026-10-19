"use client";

/**
 * In-app guide for signed-in staff. Organised by the stage a project is at (set up → quote → sign
 * → build → finish) instead of by screen, because "what do I do next?" is the question people
 * arrive with. Each step names the exact button label from dictionaries.ts and, where the step
 * isn't tied to one project, links straight to the screen. Copy lives in lib/guide/content.ts.
 */

import Link from "next/link";
import { GlossaryList } from "@/components/guide/GlossaryList";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { APP_GUIDE as G, GLOSSARY, pick, type Localized } from "@/lib/guide/content";

export default function HelpPage() {
  const { locale } = useLocale();
  const p = (text: Localized) => pick(text, locale);
  const num = (n: number) => new Intl.NumberFormat(locale === "ar" ? "ar-EG" : "en-EG").format(n);

  return (
    <div className="ledger flex flex-col gap-8 text-[var(--l-text)]">
      <div>
        <h1 className="text-2xl font-semibold tracking-[-0.01em]">{p(G.title)}</h1>
        <p className="mt-2 max-w-2xl leading-relaxed text-[var(--l-muted)]">{p(G.intro)}</p>
      </div>

      <nav aria-label={p(G.onThisPage)} className="flex flex-wrap gap-2">
        {G.stages.map((stage) => (
          <a
            key={stage.id}
            href={`#${stage.id}`}
            className="rounded-full border border-[var(--l-border)] bg-[var(--l-surface)] px-3 py-1.5 text-sm hover:border-[var(--l-muted)]"
          >
            {p(stage.title)}
          </a>
        ))}
        <a
          href="#glossary"
          className="rounded-full border border-[var(--l-border)] bg-[var(--l-surface)] px-3 py-1.5 text-sm hover:border-[var(--l-muted)]"
        >
          {p(G.glossaryTitle)}
        </a>
      </nav>

      {G.stages.map((stage) => (
        <section
          key={stage.id}
          id={stage.id}
          aria-labelledby={`${stage.id}-title`}
          className="scroll-mt-6 rounded-lg border border-[var(--l-border)] bg-[var(--l-surface)]"
        >
          <div className="border-b border-[var(--l-border)] px-5 py-4">
            <h2 id={`${stage.id}-title`} className="text-lg font-semibold">
              {p(stage.title)}
            </h2>
            <p className="mt-1 text-sm text-[var(--l-muted)]">{p(stage.summary)}</p>
          </div>
          <ol className="divide-y divide-[var(--l-rule)]">
            {stage.steps.map((step, i) => (
              <li key={step.title.en} className="flex gap-4 px-5 py-4">
                <span
                  aria-hidden="true"
                  className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--l-raised)] text-sm font-semibold"
                >
                  {num(i + 1)}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <h3 className="font-semibold">{p(step.title)}</h3>
                    {step.where ? (
                      <span className="text-xs text-[var(--l-muted)]">
                        {p(G.whereLabel)}: {p(step.where)}
                      </span>
                    ) : null}
                  </div>
                  <p className="mt-1 text-sm leading-relaxed">{p(step.body)}</p>
                  {step.tip ? (
                    <p className="mt-2 rounded-md bg-[var(--l-warn-bg)] px-3 py-2 text-sm text-[var(--l-warn-ink)]">
                      <span className="font-medium">{p(G.tipLabel)}:</span> {p(step.tip)}
                    </p>
                  ) : null}
                  {step.href ? (
                    <Link
                      href={step.href}
                      className="mt-2 inline-block text-sm font-medium underline underline-offset-2 hover:no-underline"
                    >
                      {p(G.openLabel)} {p(step.where ?? step.title)}
                    </Link>
                  ) : null}
                </div>
              </li>
            ))}
          </ol>
        </section>
      ))}

      <section className="grid gap-6 md:grid-cols-2">
        <div className="rounded-lg border border-[var(--l-border)] bg-[var(--l-surface)] p-5">
          <h2 className="text-lg font-semibold">{p(G.clientTitle)}</h2>
          <ul className="mt-3 flex list-disc flex-col gap-2 ps-5 text-sm leading-relaxed">
            {G.clientPoints.map((point) => (
              <li key={point.en}>{p(point)}</li>
            ))}
          </ul>
        </div>
        <div className="rounded-lg border border-[var(--l-border)] bg-[var(--l-surface)] p-5">
          <h2 className="text-lg font-semibold">{p(G.stillStuckTitle)}</h2>
          <p className="mt-3 text-sm leading-relaxed">{p(G.stillStuckBody)}</p>
        </div>
      </section>

      <section id="glossary" aria-labelledby="glossary-title" className="scroll-mt-6">
        <h2 id="glossary-title" className="text-lg font-semibold">
          {p(G.glossaryTitle)}
        </h2>
        <GlossaryList entries={GLOSSARY} locale={locale} className="mt-4" />
      </section>
    </div>
  );
}
