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
import { useState, type ReactNode } from "react";
import { useAuth } from "@/lib/auth/AuthContext";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { Locale } from "@/lib/i18n/dictionaries";
import { NotificationBell } from "@/components/layout/NotificationBell";
import { FitoutLogo } from "@/components/brand/FitoutLogo";

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

function NavLink({
  href,
  label,
  isActive,
  comingSoon,
  onClick,
}: {
  href: string | null;
  label: string;
  isActive: boolean;
  comingSoon?: string;
  onClick?: () => void;
}) {
  if (!href) {
    return (
      <span
        title={comingSoon}
        className="cursor-not-allowed rounded-md px-3 py-2 text-sm font-medium text-zinc-400 dark:text-zinc-600"
      >
        {label}
      </span>
    );
  }
  return (
    <Link
      href={href}
      onClick={onClick}
      className={`rounded-md px-3 py-2 text-sm font-medium transition-colors ${
        isActive
          ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
          : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
      }`}
    >
      {label}
    </Link>
  );
}

export function AppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const { user, logout } = useAuth();
  const { t, locale, setLocale } = useLocale();
  const [menuOpen, setMenuOpen] = useState(false);

  const allNavItems = user?.is_platform_owner
    ? [{ key: "platform" as const, href: "/platform" }, ...NAV_ITEMS]
    : NAV_ITEMS;

  const localeSwitcher = (
    <>
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
    </>
  );

  return (
    <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-black">
      <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3">
          <div className="flex min-w-0 items-center gap-6">
            <FitoutLogo className="shrink-0" />
            <nav className="hidden items-center gap-1 lg:flex">
              {allNavItems.map((item) => (
                <NavLink
                  key={item.key}
                  href={item.href}
                  label={t(item.key === "platform" ? "nav.platform" : `nav.${item.key}`)}
                  isActive={Boolean(item.href && pathname.startsWith(item.href))}
                  comingSoon={t("nav.comingSoon")}
                />
              ))}
            </nav>
          </div>

          <div className="hidden items-center gap-3 lg:flex">
            <NotificationBell />
            {localeSwitcher}
            {user ? (
              <span className="max-w-[10rem] truncate text-sm text-zinc-600 dark:text-zinc-300">
                {user.name}
              </span>
            ) : null}
            <button
              onClick={() => logout()}
              className="text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
            >
              {t("nav.logout")}
            </button>
          </div>

          <div className="flex items-center gap-2 lg:hidden">
            <NotificationBell />
            <button
              type="button"
              aria-label={t("nav.menu")}
              aria-expanded={menuOpen}
              onClick={() => setMenuOpen((open) => !open)}
              className="flex h-9 w-9 items-center justify-center rounded-md text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="h-5 w-5">
                {menuOpen ? (
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                ) : (
                  <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                )}
              </svg>
            </button>
          </div>
        </div>

        {menuOpen ? (
          <div className="border-t border-zinc-200 px-4 pb-4 pt-2 dark:border-zinc-800 lg:hidden">
            <nav className="flex flex-col gap-1">
              {allNavItems.map((item) => (
                <NavLink
                  key={item.key}
                  href={item.href}
                  label={t(item.key === "platform" ? "nav.platform" : `nav.${item.key}`)}
                  isActive={Boolean(item.href && pathname.startsWith(item.href))}
                  comingSoon={t("nav.comingSoon")}
                  onClick={() => setMenuOpen(false)}
                />
              ))}
            </nav>
            <div className="mt-3 flex items-center justify-between gap-3 border-t border-zinc-200 pt-3 dark:border-zinc-800">
              {localeSwitcher}
              {user ? (
                <span className="truncate text-sm text-zinc-600 dark:text-zinc-300">{user.name}</span>
              ) : null}
            </div>
            <button
              onClick={() => {
                setMenuOpen(false);
                logout();
              }}
              className="mt-3 w-full rounded-md border border-zinc-300 px-3 py-2 text-left text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
            >
              {t("nav.logout")}
            </button>
          </div>
        ) : null}
      </header>
      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6">{children}</main>
    </div>
  );
}
