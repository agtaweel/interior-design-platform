"use client";

/**
 * Top-level nav, cut to five (docs/SIMPLIFICATION_REVIEW.md P2.2):
 *   Home · Requests · Clients · Projects · More ▾
 * The daily-work screens stay one click away; everything else (Media, Suppliers, Reports,
 * Settings, Help — plus Platform for the platform owner) moves under "More".
 * "Requests" is one entry for both ways a new job arrives — the Leads pipeline and marketplace
 * Inquiries; the two pages stay separate and share a tab bar (components/layout/RequestsTabs).
 * Below lg the same items appear in the hamburger menu, More's items listed after a divider.
 */

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { useAuth } from "@/lib/auth/AuthContext";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { Locale, TranslationKey } from "@/lib/i18n/dictionaries";
import { NotificationBell } from "@/components/layout/NotificationBell";
import { FitoutLogo } from "@/components/brand/FitoutLogo";
import { ThemeToggle } from "@/components/ui/ThemeToggle";

interface NavItem {
  label: TranslationKey;
  href: string;
  /** Extra path prefixes that count as "inside" this item. */
  alsoActive?: string[];
}

const PRIMARY_ITEMS: NavItem[] = [
  { label: "nav.dashboard", href: "/dashboard" },
  { label: "nav.requests", href: "/leads", alsoActive: ["/inquiries"] },
  { label: "nav.clients", href: "/clients", alsoActive: ["/properties"] },
  { label: "nav.projects", href: "/projects" },
];

const MORE_ITEMS: NavItem[] = [
  { label: "nav.media", href: "/media" },
  { label: "nav.suppliers", href: "/suppliers" },
  { label: "nav.reports", href: "/reports" },
  { label: "nav.settings", href: "/settings" },
  { label: "nav.help", href: "/help" },
];

function isActive(item: NavItem, pathname: string) {
  return [item.href, ...(item.alsoActive ?? [])].some((prefix) => pathname.startsWith(prefix));
}

function NavLink({
  href,
  label,
  isActive,
  onClick,
  role,
}: {
  href: string;
  label: string;
  isActive: boolean;
  onClick?: () => void;
  role?: "menuitem";
}) {
  // Ledger nav: the active item is a neutral grey pill with the label in ink — no brand colour
  // in the chrome (the amber is kept for the logo and for collected money on the dashboard).
  return (
    <Link
      href={href}
      onClick={onClick}
      role={role}
      aria-current={isActive ? "page" : undefined}
      className={`block whitespace-nowrap rounded-md px-2.5 py-2 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)] ${
        isActive
          ? "bg-[var(--l-active)] font-medium text-[var(--l-text)]"
          : "text-[var(--l-muted)] hover:bg-[var(--l-raised)] hover:text-[var(--l-text)]"
      }`}
    >
      {label}
    </Link>
  );
}

/** "More ▾" disclosure. Closes on outside click, Escape, or navigating. */
function MoreMenu({ items, pathname, label }: { items: NavItem[]; pathname: string; label: string }) {
  const { t } = useLocale();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const anyActive = items.some((item) => isActive(item, pathname));

  useEffect(() => {
    if (!open) return;
    function onPointer(e: PointerEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false);
    }
    document.addEventListener("pointerdown", onPointer);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("pointerdown", onPointer);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        className={`flex items-center gap-1 whitespace-nowrap rounded-md px-2.5 py-2 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)] ${
          anyActive
            ? "bg-[var(--l-active)] font-medium text-[var(--l-text)]"
            : "text-[var(--l-muted)] hover:bg-[var(--l-raised)] hover:text-[var(--l-text)]"
        }`}
      >
        {label}
        <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4" aria-hidden="true">
          <path d="M5.5 7.5 10 12l4.5-4.5" stroke="currentColor" strokeWidth="1.6" fill="none" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>
      {open ? (
        <div
          role="menu"
          className="absolute start-0 top-full z-20 mt-1 min-w-44 rounded-md border border-[var(--l-border)] bg-[var(--l-surface)] p-1 shadow-lg"
        >
          {items.map((item) => (
            <NavLink
              key={item.href}
              role="menuitem"
              href={item.href}
              label={t(item.label)}
              isActive={isActive(item, pathname)}
              onClick={() => setOpen(false)}
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}

// Help also sits with the header controls: a "?" is recognisable to people who don't read the
// labels, and it stays visible on phones without opening the menu.
function HelpLink({ label, isActive }: { label: string; isActive: boolean }) {
  return (
    <Link
      href="/help"
      aria-label={label}
      title={label}
      aria-current={isActive ? "page" : undefined}
      className={`flex h-9 w-9 items-center justify-center rounded-md hover:bg-[var(--l-raised)] hover:text-[var(--l-text)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)] ${
        isActive ? "bg-[var(--l-active)] text-[var(--l-text)]" : "text-[var(--l-muted)]"
      }`}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} className="h-5 w-5" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6" />
        <circle cx="12" cy="17" r="0.6" fill="currentColor" />
      </svg>
    </Link>
  );
}

export function AppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const { user, logout } = useAuth();
  const { t, locale, setLocale } = useLocale();
  const [menuOpen, setMenuOpen] = useState(false);

  const moreItems: NavItem[] = user?.is_platform_owner
    ? [...MORE_ITEMS, { label: "nav.platform", href: "/platform" }]
    : MORE_ITEMS;

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
        <div className="flex w-full items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
          <div className="flex min-w-0 items-center gap-5">
            <FitoutLogo className="shrink-0" />
            <nav className="hidden items-center gap-0.5 lg:flex">
              {PRIMARY_ITEMS.map((item) => (
                <NavLink key={item.href} href={item.href} label={t(item.label)} isActive={isActive(item, pathname)} />
              ))}
              <MoreMenu items={moreItems} pathname={pathname} label={t("nav.more")} />
            </nav>
          </div>

          <div className="hidden shrink-0 items-center gap-2 lg:flex">
            <ThemeToggle />
            <HelpLink label={t("nav.help")} isActive={pathname.startsWith("/help")} />
            <NotificationBell />
            {localeSwitcher}
            {user ? (
              <span title={user.name} className="max-w-[6rem] truncate text-sm text-[var(--l-muted)]">
                {user.name}
              </span>
            ) : null}
            <button
              onClick={() => logout()}
              className="whitespace-nowrap rounded text-sm font-medium text-[var(--l-text)] underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)]"
            >
              {t("nav.logout")}
            </button>
          </div>

          <div className="flex items-center gap-2 lg:hidden">
            <ThemeToggle />
            <HelpLink label={t("nav.help")} isActive={pathname.startsWith("/help")} />
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
              {PRIMARY_ITEMS.map((item) => (
                <NavLink
                  key={item.href}
                  href={item.href}
                  label={t(item.label)}
                  isActive={isActive(item, pathname)}
                  onClick={() => setMenuOpen(false)}
                />
              ))}
              <div className="my-1 border-t border-[var(--l-border)]" role="separator" />
              {moreItems.map((item) => (
                <NavLink
                  key={item.href}
                  href={item.href}
                  label={t(item.label)}
                  isActive={isActive(item, pathname)}
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
      <main className="w-full flex-1 px-4 py-6 sm:px-6 lg:px-8">{children}</main>
    </div>
  );
}
