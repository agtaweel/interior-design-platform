"use client";

/**
 * Desktop top-level nav per docs/PROJECT_CONTEXT.md + BRD extensions:
 *   Dashboard / Leads / Clients / Projects / Media / Suppliers / Reports / Settings
 * Suppliers is now live (BRD "Procurement & Supplier Intelligence" — the org-wide supplier
 * directory; purchase orders themselves live inside each project's Expenses tab, per BRD S18
 * "Expenses/Suppliers"). "Media" is an org-wide gallery across every project's Documents-tab
 * attachments (not a PROJECT_CONTEXT.md-listed screen — added alongside the Documents tab
 * feature), placed after Projects since it's a cross-project view in the same spirit as Reports.
 */

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { ReactNode } from "react";
import { useAuth } from "@/lib/auth/AuthContext";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { Locale } from "@/lib/i18n/dictionaries";
import { NotificationBell } from "@/components/layout/NotificationBell";

interface NavItem {
  key: "dashboard" | "leads" | "clients" | "projects" | "media" | "suppliers" | "reports" | "settings";
  href: string | null;
}

const NAV_ITEMS: NavItem[] = [
  { key: "dashboard", href: "/dashboard" },
  { key: "leads", href: "/leads" },
  { key: "clients", href: "/clients" },
  { key: "projects", href: "/projects" },
  { key: "media", href: "/media" },
  { key: "suppliers", href: "/suppliers" },
  { key: "reports", href: "/reports" },
  { key: "settings", href: "/settings" },
];

export function AppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const { user, logout } = useAuth();
  const { t, locale, setLocale } = useLocale();

  return (
    <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-black">
      <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3">
          <div className="flex items-center gap-6">
            <span className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("app.name")}
            </span>
            <nav className="flex items-center gap-1">
              {user?.is_platform_owner ? (
                <Link
                  href="/platform"
                  className={`rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                    pathname.startsWith("/platform")
                      ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                      : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                  }`}
                >
                  {t("nav.platform")}
                </Link>
              ) : null}
              {NAV_ITEMS.map((item) => {
                const isActive = item.href && pathname.startsWith(item.href);
                if (!item.href) {
                  return (
                    <span
                      key={item.key}
                      title={t("nav.comingSoon")}
                      className="cursor-not-allowed rounded-md px-3 py-2 text-sm font-medium text-zinc-400 dark:text-zinc-600"
                    >
                      {t(`nav.${item.key}`)}
                    </span>
                  );
                }
                return (
                  <Link
                    key={item.key}
                    href={item.href}
                    className={`rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                      isActive
                        ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
                        : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    }`}
                  >
                    {t(`nav.${item.key}`)}
                  </Link>
                );
              })}
            </nav>
          </div>

          <div className="flex items-center gap-3">
            <NotificationBell />
            <label className="sr-only" htmlFor="locale-switcher">
              {t("nav.language")}
            </label>
            <select
              id="locale-switcher"
              value={locale}
              onChange={(e) => setLocale(e.target.value as Locale)}
              className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-900"
            >
              <option value="en">English</option>
              <option value="ar">العربية</option>
            </select>
            {user ? (
              <span className="text-sm text-zinc-600 dark:text-zinc-300">{user.name}</span>
            ) : null}
            <button
              onClick={() => logout()}
              className="text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
            >
              {t("nav.logout")}
            </button>
          </div>
        </div>
      </header>
      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6">{children}</main>
    </div>
  );
}
