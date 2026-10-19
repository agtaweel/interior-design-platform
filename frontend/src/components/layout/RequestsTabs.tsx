"use client";

/**
 * Sub-navigation for the "Requests" nav entry: the Leads pipeline and marketplace Inquiries are
 * two separate screens (different data, different actions) but one idea to a user — "people
 * asking me for a job" — so they share this tab bar instead of two top-level nav items.
 */

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

const TABS: { href: string; label: TranslationKey }[] = [
  { href: "/leads", label: "requests.tabs.leads" },
  { href: "/inquiries", label: "requests.tabs.inquiries" },
];

export function RequestsTabs() {
  const pathname = usePathname();
  const { t } = useLocale();

  return (
    <nav aria-label={t("nav.requests")} className="flex gap-1 border-b border-zinc-200 dark:border-zinc-800">
      {TABS.map((tab) => {
        const active = pathname.startsWith(tab.href);
        return (
          <Link
            key={tab.href}
            href={tab.href}
            aria-current={active ? "page" : undefined}
            className={`border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
              active
                ? "border-zinc-900 text-zinc-900 dark:border-zinc-100 dark:text-zinc-50"
                : "border-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            }`}
          >
            {t(tab.label)}
          </Link>
        );
      })}
    </nav>
  );
}
