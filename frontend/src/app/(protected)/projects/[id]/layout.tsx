"use client";

/**
 * In-project tab nav per docs/PROJECT_CONTEXT.md + BRD's UX/Screens table (§13):
 *   Overview / BOQ & Pricing / Proposal / Contract / Payments / Change Orders / Execution /
 *   Expenses / Snagging / Handover / Documents / Activity
 * Execution (BRD S16/S17: tasks + site reports), Expenses (BRD S18 "Expenses/Suppliers: actual
 * cost capture" — purchase orders + expenses live together here, matching that screen's own
 * bundled title) and Snagging/Handover (BRD S19/S20) are now live. Activity (an audit-log viewer)
 * is not shown at all until it exists — a permanently disabled tab only confused people; the
 * org-wide audit log lives under Settings.
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
    | "documents";
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
    | "documents";
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
