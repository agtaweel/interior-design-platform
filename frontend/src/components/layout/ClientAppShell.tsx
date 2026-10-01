"use client";

/**
 * BRD v4 "Client Marketplace" Phase C — the client-facing counterpart to AppShell.tsx, parallel
 * but deliberately simpler: no locale switcher (client pages use plain hardcoded English, same
 * established precedent as the marketplace browse/signup pages — see those pages' docblocks),
 * no NotificationBell (no client-facing notification system exists, out of scope per the
 * approved plan), no org-switcher (a ClientUser belongs to no organization).
 */

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState, type ReactNode } from "react";
import { useClientAuth } from "@/lib/auth/ClientAuthContext";
import { FitoutLogo } from "@/components/brand/FitoutLogo";
import { ThemeToggle } from "@/components/ui/ThemeToggle";

interface NavItem {
  key: "dashboard" | "projects" | "deals" | "messages";
  href: string;
  label: string;
}

const NAV_ITEMS: NavItem[] = [
  { key: "dashboard", href: "/client/dashboard", label: "Dashboard" },
  { key: "projects", href: "/client/projects", label: "Projects" },
  { key: "deals", href: "/client/deals", label: "Deals" },
  { key: "messages", href: "/client/messages", label: "Messages" },
];

function NavLink({
  href,
  label,
  isActive,
  onClick,
}: {
  href: string;
  label: string;
  isActive: boolean;
  onClick?: () => void;
}) {
  return (
    <Link
      href={href}
      onClick={onClick}
      className={`rounded-md px-3 py-2 text-sm font-medium transition-colors ${
        isActive
          ? "bg-amber-600 text-white dark:bg-amber-500 dark:text-zinc-950"
          : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
      }`}
    >
      {label}
    </Link>
  );
}

export function ClientAppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const { client, logout } = useClientAuth();
  const [menuOpen, setMenuOpen] = useState(false);

  return (
    <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-black">
      <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3">
          <div className="flex min-w-0 items-center gap-6">
            <Link href="/client/dashboard" className="shrink-0">
              <FitoutLogo />
            </Link>
            <nav className="hidden items-center gap-1 md:flex">
              {NAV_ITEMS.map((item) => (
                <NavLink
                  key={item.key}
                  href={item.href}
                  label={item.label}
                  isActive={pathname.startsWith(item.href)}
                />
              ))}
            </nav>
          </div>

          <div className="hidden items-center gap-3 md:flex">
            <ThemeToggle />
            {client ? (
              <span className="max-w-[10rem] truncate text-sm text-zinc-600 dark:text-zinc-300">
                {client.name}
              </span>
            ) : null}
            <button
              onClick={() => logout()}
              className="text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
            >
              Log out
            </button>
          </div>

          <div className="flex items-center gap-2 md:hidden">
            <ThemeToggle />
            <button
              type="button"
              aria-label="Menu"
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
          <div className="border-t border-zinc-200 px-4 pb-4 pt-2 dark:border-zinc-800 md:hidden">
            <nav className="flex flex-col gap-1">
              {NAV_ITEMS.map((item) => (
                <NavLink
                  key={item.key}
                  href={item.href}
                  label={item.label}
                  isActive={pathname.startsWith(item.href)}
                  onClick={() => setMenuOpen(false)}
                />
              ))}
            </nav>
            <div className="mt-3 border-t border-zinc-200 pt-3 dark:border-zinc-800">
              {client ? (
                <p className="mb-3 truncate text-sm text-zinc-600 dark:text-zinc-300">{client.name}</p>
              ) : null}
              <button
                onClick={() => {
                  setMenuOpen(false);
                  logout();
                }}
                className="w-full rounded-md border border-zinc-300 px-3 py-2 text-left text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
              >
                Log out
              </button>
            </div>
          </div>
        ) : null}
      </header>
      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-6">{children}</main>
    </div>
  );
}
