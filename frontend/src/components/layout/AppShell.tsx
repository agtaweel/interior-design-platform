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
import { ThemeToggle } from "@/components/ui/ThemeToggle";

interface NavItem {
  key:
    | "dashboard"
    | "leads"
    | "clients"
    | "projects"
    | "media"
    | "inquiries"
    | "suppliers"
    | "reports"
    | "settings";
  href: string | null;
}

const NAV_ITEMS: NavItem[] = [
  { key: "dashboard", href: "/dashboard" },
  { key: "leads", href: "/leads" },
  { key: "clients", href: "/clients" },
  { key: "projects", href: "/projects" },
  { key: "media", href: "/media" },
  { key: "inquiries", href: "/inquiries" },
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
        className="cursor-not-allowed rounded-md px-3 py-2 text-sm text-[var(--l-muted)] opacity-60"
      >
        {label}
      </span>
    );
  }
  // Ledger nav: the active item is a neutral grey pill with the label in ink — no brand colour
  // in the chrome (the amber is kept for the logo and for collected money on the dashboard).
  return (
    <Link
      href={href}
      onClick={onClick}
      aria-current={isActive ? "page" : undefined}
      className={`rounded-md px-3 py-2 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)] ${
        isActive
          ? "bg-[var(--l-active)] font-medium text-[var(--l-text)]"
          : "text-[var(--l-muted)] hover:bg-[var(--l-raised)] hover:text-[var(--l-text)]"
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
        className="h-9 rounded-md border border-[var(--l-border)] bg-[var(--l-surface)] px-2 text-sm text-[var(--l-text)] hover:border-[var(--l-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)]"
      >
        <option value="en">English</option>
        <option value="ar">العربية</option>
      </select>
    </>
  );

  return (
    <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-black">
      <header className="ledger border-b border-[var(--l-border)] bg-[var(--l-surface)]">
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
            <ThemeToggle />
            <NotificationBell />
            {localeSwitcher}
            {user ? (
              <span className="max-w-[10rem] truncate text-sm text-[var(--l-muted)]">
                {user.name}
              </span>
            ) : null}
            <button
              onClick={() => logout()}
              className="rounded text-sm font-medium text-[var(--l-text)] underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)]"
            >
              {t("nav.logout")}
            </button>
          </div>

          <div className="flex items-center gap-2 lg:hidden">
            <ThemeToggle />
            <NotificationBell />
            <button
              type="button"
              aria-label={t("nav.menu")}
              aria-expanded={menuOpen}
              onClick={() => setMenuOpen((open) => !open)}
              className="flex h-9 w-9 items-center justify-center rounded-md text-[var(--l-muted)] hover:bg-[var(--l-raised)] hover:text-[var(--l-text)]"
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
          <div className="border-t border-[var(--l-border)] px-4 pb-4 pt-2 lg:hidden">
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
            <div className="mt-3 flex items-center justify-between gap-3 border-t border-[var(--l-border)] pt-3">
              {localeSwitcher}
              {user ? (
                <span className="truncate text-sm text-[var(--l-muted)]">{user.name}</span>
              ) : null}
            </div>
            <button
              onClick={() => {
                setMenuOpen(false);
                logout();
              }}
              className="mt-3 h-11 w-full rounded-md border border-[var(--l-border)] px-3 text-start text-sm font-medium text-[var(--l-text)] hover:bg-[var(--l-raised)]"
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
