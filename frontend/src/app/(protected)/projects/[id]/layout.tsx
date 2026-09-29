"use client";

/**
 * In-project tab nav per docs/PROJECT_CONTEXT.md + BRD's UX/Screens table (§13):
 *   Overview / BOQ & Pricing / Proposal / Contract / Payments / Change Orders / Execution /
 *   Expenses / Snagging / Handover / Documents / Activity
 * Execution (BRD S16/S17: tasks + site reports), Expenses (BRD S18 "Expenses/Suppliers: actual
 * cost capture" — purchase orders + expenses live together here, matching that screen's own
 * bundled title) and Snagging/Handover (BRD S19/S20) are now live. Only Activity (an audit-log
 * viewer) remains disabled — no BRD screen defines its UI beyond the NFR-level "audit trail"
 * requirement, so it's out of this pass's scope.
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
    | "expenses"
    | "snagging"
    | "handover"
    | "documents"
    | "activity";
  href:
    | ""
    | "boq"
    | "proposal"
    | "contract"
    | "payments"
    | "change-orders"
    | "execution"
    | "expenses"
    | "snagging"
    | "handover"
    | "documents"
    | null;
}

const TAB_ITEMS: TabItem[] = [
  { key: "overview", href: "" },
  { key: "boq", href: "boq" },
  { key: "proposal", href: "proposal" },
  { key: "contract", href: "contract" },
  { key: "payments", href: "payments" },
  { key: "changeOrders", href: "change-orders" },
  { key: "execution", href: "execution" },
  { key: "expenses", href: "expenses" },
  { key: "snagging", href: "snagging" },
  { key: "handover", href: "handover" },
  { key: "documents", href: "documents" },
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
