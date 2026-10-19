"use client";

/**
 * BRD v3 §17 "Client Portal" — shared shell (header + tab nav) for every
 * `/p/client-portal/[token]/...` page. Lives OUTSIDE the `(protected)` route group, same
 * isolation reasoning as `/p/proposals/[token]` (see that page's docblock): no redirect-to-login,
 * no internal nav, no `lib/auth/*` import anywhere under this tree — the token in the URL is the
 * only credential.
 *
 * Each page fetches its own data independently (no shared overview/context here) — this layout
 * only renders the tab bar, which needs nothing but the token itself to build its links.
 */

import { useEffect, useRef } from "react";
import Link from "next/link";
import { usePathname, useParams } from "next/navigation";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { Locale, TranslationKey } from "@/lib/i18n/dictionaries";
import { FitoutLogo } from "@/components/brand/FitoutLogo";
import { ThemeToggle } from "@/components/ui/ThemeToggle";

const TABS: Array<{ href: (token: string) => string; labelKey: TranslationKey; match: RegExp }> = [
  { href: (t) => `/p/client-portal/${t}`, labelKey: "clientPortal.tabs.overview", match: /^\/p\/client-portal\/[^/]+\/?$/ },
  { href: (t) => `/p/client-portal/${t}/proposals`, labelKey: "clientPortal.tabs.proposals", match: /\/proposals/ },
  { href: (t) => `/p/client-portal/${t}/contract`, labelKey: "clientPortal.tabs.contract", match: /\/contract/ },
  { href: (t) => `/p/client-portal/${t}/payments`, labelKey: "clientPortal.tabs.payments", match: /\/payments/ },
  { href: (t) => `/p/client-portal/${t}/change-orders`, labelKey: "clientPortal.tabs.changeOrders", match: /\/change-orders/ },
  { href: (t) => `/p/client-portal/${t}/gallery`, labelKey: "clientPortal.tabs.gallery", match: /\/gallery/ },
];

export default function ClientPortalLayout({ children }: { children: React.ReactNode }) {
  const params = useParams<{ token: string }>();
  const pathname = usePathname();
  const token = params.token;
  const { t, locale, setLocale } = useLocale();
  const activeTabRef = useRef<HTMLAnchorElement>(null);

  // On a phone the tab row scrolls sideways; bring the current tab into view whenever the page
  // (or the language, which changes the tabs' widths and direction) changes. "nearest" leaves
  // the row alone when the tab is already visible, so desktop never moves. Deferred one frame:
  // child effects run before LocaleProvider's, which is what flips <html dir> to rtl — measured
  // any earlier, the row is still laid out left-to-right.
  useEffect(() => {
    const frame = requestAnimationFrame(() =>
      activeTabRef.current?.scrollIntoView({ block: "nearest", inline: "nearest" }),
    );
    return () => cancelAnimationFrame(frame);
  }, [pathname, locale]);

  return (
    <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-zinc-950">
      {/* Two rows on the pages' own 672px column (px-4, same as every portal <main>): the brand
          and controls, then the tabs on a row of their own. All six tabs fit that row on
          desktop; on a phone it scrolls sideways and the current tab is kept in view. */}
      <header className="ledger sticky top-0 z-10 border-b border-[var(--l-border)] bg-[var(--l-surface)]">
        <div className="mx-auto max-w-2xl px-4">
          <div className="flex h-14 items-center justify-between gap-3">
            <FitoutLogo className="shrink-0" />
            <div className="flex items-center gap-2">
              <ThemeToggle className="shrink-0" />
              <select
                aria-label={t("nav.language")}
                value={locale}
                onChange={(e) => setLocale(e.target.value as Locale)}
                className="h-9 shrink-0 rounded-md border border-[var(--l-border)] bg-[var(--l-surface)] px-2 text-sm text-[var(--l-text)] hover:border-[var(--l-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)]"
              >
                <option value="en">English</option>
                <option value="ar">العربية</option>
              </select>
            </div>
          </div>
          {/* -mx-3 lets the first tab's label sit on the content's edge (the pill's own 12px
              padding hangs into the margin), so the tabs line up with the page below. */}
          <nav
            aria-label={t("clientPortal.tabs.label")}
            className="-mx-3 flex gap-1 overflow-x-auto pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
          >
            {TABS.map((tab) => {
              const active = tab.match.test(pathname);
              return (
                <Link
                  key={tab.labelKey}
                  ref={active ? activeTabRef : undefined}
                  href={tab.href(token)}
                  aria-current={active ? "page" : undefined}
                  // Ledger tabs, same as the internal app: neutral grey pill for the current tab.
                  className={`shrink-0 whitespace-nowrap rounded-md px-3 py-2 text-sm focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[var(--l-text)] ${
                    active
                      ? "bg-[var(--l-active)] font-medium text-[var(--l-text)]"
                      : "text-[var(--l-muted)] hover:bg-[var(--l-raised)] hover:text-[var(--l-text)]"
                  }`}
                >
                  {t(tab.labelKey)}
                </Link>
              );
            })}
          </nav>
        </div>
      </header>
      <div className="flex flex-1 flex-col">{children}</div>
    </div>
  );
}
