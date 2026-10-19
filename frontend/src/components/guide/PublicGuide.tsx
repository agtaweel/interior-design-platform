"use client";

/**
 * Public guide — what Fitout is and how one project flows through it, for a visitor who hasn't
 * signed up. Rendered at /guide and, for signed-out visitors, at / (see app/page.tsx). Linked
 * from /login and /signup.
 * Copy lives in lib/guide/content.ts (both languages, type-checked) rather than dictionaries.ts.
 */

import Link from "next/link";
import { FitoutLogo } from "@/components/brand/FitoutLogo";
import { LanguageToggle } from "@/components/ui/LanguageToggle";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { GlossaryList } from "@/components/guide/GlossaryList";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { GLOSSARY, PUBLIC_GUIDE as G, pick } from "@/lib/guide/content";

const PRIMARY_LINK =
  "inline-flex h-11 items-center justify-center rounded-md bg-[var(--l-primary)] px-5 text-sm font-medium text-[var(--l-primary-ink)] hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)]";
const SECONDARY_LINK =
  "inline-flex h-11 items-center justify-center rounded-md border border-[var(--l-border)] bg-[var(--l-surface)] px-5 text-sm font-medium text-[var(--l-text)] hover:border-[var(--l-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)]";

export function PublicGuide() {
  const { locale, t } = useLocale();
  const p = (text: { en: string; ar: string }) => pick(text, locale);
  const num = (n: number) => new Intl.NumberFormat(locale === "ar" ? "ar-EG" : "en-EG").format(n);

  return (
    <div className="ledger flex min-h-screen flex-col bg-zinc-50 text-[var(--l-text)] dark:bg-black">
      <header className="border-b border-[var(--l-border)] bg-[var(--l-surface)]">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
          <Link href="/guide" aria-label="Fitout">
            <FitoutLogo />
          </Link>
          <div className="flex items-center gap-2">
            <ThemeToggle />
            <LanguageToggle />
            <Link href="/login" className="hidden px-2 text-sm font-medium hover:underline sm:inline">
              {t("login.submit")}
            </Link>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-5xl flex-1 px-4">
        {/* Hero */}
        <section className="py-12 sm:py-16">
          <p className="text-sm font-medium text-[var(--l-data)]">{p(G.eyebrow)}</p>
          <h1 className="mt-3 max-w-3xl text-3xl font-semibold leading-tight tracking-[-0.01em] sm:text-4xl">
            {p(G.title)}
          </h1>
          <p className="mt-4 max-w-2xl text-base leading-relaxed text-[var(--l-muted)]">{p(G.intro)}</p>
          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Link href="/signup" className={PRIMARY_LINK}>
              {p(G.ctaPrimary)}
            </Link>
            <Link href="/login" className={SECONDARY_LINK}>
              {p(G.ctaSecondary)}
            </Link>
          </div>
        </section>

        {/* Journey */}
        <section aria-labelledby="journey" className="border-t border-[var(--l-border)] py-12">
          <h2 id="journey" className="text-xl font-semibold">
            {p(G.journeyTitle)}
          </h2>
          <ol className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {G.journey.map((step, i) => (
              <li
                key={step.title.en}
                className="rounded-lg border border-[var(--l-border)] bg-[var(--l-surface)] p-4"
              >
                <span
                  aria-hidden="true"
                  className="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--l-raised)] text-sm font-semibold"
                >
                  {num(i + 1)}
                </span>
                <h3 className="mt-3 font-semibold">{p(step.title)}</h3>
                <p className="mt-1 text-sm leading-relaxed text-[var(--l-muted)]">{p(step.body)}</p>
              </li>
            ))}
          </ol>
        </section>

        {/* Benefits */}
        <section aria-labelledby="benefits" className="border-t border-[var(--l-border)] py-12">
          <h2 id="benefits" className="text-xl font-semibold">
            {p(G.benefitsTitle)}
          </h2>
          <div className="mt-6 grid gap-x-8 gap-y-6 sm:grid-cols-2">
            {G.benefits.map((b) => (
              <div key={b.title.en} className="border-s-2 border-[var(--l-data)] ps-4">
                <h3 className="font-semibold">{p(b.title)}</h3>
                <p className="mt-1 text-sm leading-relaxed text-[var(--l-muted)]">{p(b.body)}</p>
              </div>
            ))}
          </div>
        </section>

        {/* Client side + first 15 minutes */}
        <section className="grid gap-8 border-t border-[var(--l-border)] py-12 md:grid-cols-2">
          <div>
            <h2 className="text-xl font-semibold">{p(G.clientTitle)}</h2>
            <p className="mt-3 leading-relaxed text-[var(--l-muted)]">{p(G.clientBody)}</p>
          </div>
          <div className="rounded-lg border border-[var(--l-border)] bg-[var(--l-surface)] p-5">
            <h2 className="text-xl font-semibold">{p(G.startTitle)}</h2>
            <ol className="mt-4 flex flex-col gap-3">
              {G.start.map((line, i) => (
                <li key={line.en} className="flex gap-3 text-sm leading-relaxed">
                  <span aria-hidden="true" className="font-semibold text-[var(--l-data)]">
                    {num(i + 1)}.
                  </span>
                  <span>{p(line)}</span>
                </li>
              ))}
            </ol>
          </div>
        </section>

        {/* Glossary */}
        <section aria-labelledby="glossary" className="border-t border-[var(--l-border)] py-12">
          <h2 id="glossary" className="text-xl font-semibold">
            {p(G.glossaryTitle)}
          </h2>
          <GlossaryList entries={GLOSSARY} locale={locale} className="mt-6" />
        </section>

        {/* FAQ */}
        <section aria-labelledby="faq" className="border-t border-[var(--l-border)] py-12">
          <h2 id="faq" className="text-xl font-semibold">
            {p(G.faqTitle)}
          </h2>
          <div className="mt-6 divide-y divide-[var(--l-border)] rounded-lg border border-[var(--l-border)] bg-[var(--l-surface)]">
            {G.faq.map((item) => (
              <details key={item.q.en} className="group px-4 py-3">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-1 font-medium">
                  {p(item.q)}
                  <span aria-hidden="true" className="text-[var(--l-muted)] transition-transform group-open:rotate-45">
                    +
                  </span>
                </summary>
                <p className="mt-2 pb-1 text-sm leading-relaxed text-[var(--l-muted)]">{p(item.a)}</p>
              </details>
            ))}
          </div>
        </section>

        {/* Closing CTA */}
        <section className="border-t border-[var(--l-border)] py-12 text-center">
          <h2 className="text-2xl font-semibold">{p(G.closingTitle)}</h2>
          <div className="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
            <Link href="/signup" className={PRIMARY_LINK}>
              {p(G.ctaPrimary)}
            </Link>
            <Link href="/login" className={SECONDARY_LINK}>
              {p(G.ctaSecondary)}
            </Link>
          </div>
        </section>
      </main>
    </div>
  );
}
