"use client";

/**
 * In-project tab nav per docs/PROJECT_CONTEXT.md:
 *   Overview / BOQ & Pricing / Proposal / Contract / Payments / Change Orders / Execution /
 *   Documents / Activity
 * Overview (S06), BOQ & Pricing (S07/S08), Proposal (S09/S10, Sprint 4), Contract (S12,
 * Sprint 5), Payments (S13, Sprint 6) and Change Orders (S14, Sprint 7) exist so far — the rest
 * render disabled (no href, not clickable), same treatment AppShell gives Suppliers/Reports/
 * Settings in the top-level nav.
 */

import Link from "next/link";
import { usePathname, useParams } from "next/navigation";
import type { ReactNode } from "react";
import { useLocale } from "@/lib/i18n/LocaleProvider";

interface TabItem {
  key:
    | "overview"
    | "boq"
    | "proposal"
    | "contract"
    | "payments"
    | "changeOrders"
    | "execution"
    | "documents"
    | "activity";
  href: "" | "boq" | "proposal" | "contract" | "payments" | "change-orders" | null;
}

const TAB_ITEMS: TabItem[] = [
  { key: "overview", href: "" },
  { key: "boq", href: "boq" },
  { key: "proposal", href: "proposal" },
  { key: "contract", href: "contract" },
  { key: "payments", href: "payments" },
  { key: "changeOrders", href: "change-orders" },
  { key: "execution", href: null },
  { key: "documents", href: null },
  { key: "activity", href: null },
];

export default function ProjectLayout({ children }: { children: ReactNode }) {
  const params = useParams<{ id: string }>();
  const pathname = usePathname();
  const { t } = useLocale();

  const base = `/projects/${params.id}`;

  return (
    <div className="flex flex-col gap-6">
      <nav className="flex flex-wrap items-center gap-1 border-b border-zinc-200 dark:border-zinc-800">
        {TAB_ITEMS.map((tab) => {
          if (tab.href === null) {
            return (
              <span
                key={tab.key}
                title={t("nav.comingSoon")}
                className="cursor-not-allowed px-3 py-2 text-sm font-medium text-zinc-300 dark:text-zinc-700"
              >
                {t(`project.tabs.${tab.key}`)}
              </span>
            );
          }

          const href = tab.href === "" ? base : `${base}/${tab.href}`;
          const isActive = tab.href === "" ? pathname === base : pathname.startsWith(href);

          return (
            <Link
              key={tab.key}
              href={href}
              className={`border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                isActive
                  ? "border-zinc-900 text-zinc-900 dark:border-zinc-100 dark:text-zinc-50"
                  : "border-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
              }`}
            >
              {t(`project.tabs.${tab.key}`)}
            </Link>
          );
        })}
      </nav>
      {children}
    </div>
  );
}
