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

import Link from "next/link";
import { usePathname, useParams } from "next/navigation";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { Locale, TranslationKey } from "@/lib/i18n/dictionaries";

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

  return (
    <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-zinc-950">
      <nav className="sticky top-0 z-10 border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div className="mx-auto flex max-w-2xl items-center justify-between gap-2 px-2 py-2">
          <div className="flex gap-1 overflow-x-auto">
            {TABS.map((tab) => {
              const active = tab.match.test(pathname);
              return (
                <Link
                  key={tab.labelKey}
                  href={tab.href(token)}
                  className={`shrink-0 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap ${
                    active
                      ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                      : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                  }`}
                >
                  {t(tab.labelKey)}
                </Link>
              );
            })}
          </div>
          <select
            aria-label={t("nav.language")}
            value={locale}
            onChange={(e) => setLocale(e.target.value as Locale)}
            className="shrink-0 rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-950"
          >
            <option value="en">English</option>
            <option value="ar">العربية</option>
          </select>
        </div>
      </nav>
      <div className="flex flex-1 flex-col">{children}</div>
    </div>
  );
}
