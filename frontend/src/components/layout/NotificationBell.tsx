"use client";

/**
 * Bell icon + unread-count badge + dropdown panel, live in the app shell on every authenticated
 * page (PROJECT_CONTEXT.md Sprint 8 "Notifications"). Per that section's UX note: "no push
 * notifications, no polling requirement beyond a reasonable refresh-on-navigation" — this
 * refreshes on every pathname change (via usePathname) plus a lightweight 60s interval poll so
 * an unread count doesn't go stale during a long stay on one page, without pretending to be
 * real-time.
 *
 * `payload.summary` (see NotificationPayload in lib/api/types.ts) is rendered when present;
 * otherwise falls back to a generic label keyed by `type` (notifications.type.<type>, with a
 * `notifications.type.generic` catch-all for any future/unrecognized type).
 */

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter, usePathname } from "next/navigation";
import {
  listNotifications,
  markAllNotificationsRead,
  markNotificationRead,
} from "@/lib/api/resources/notifications";
import type { Notification } from "@/lib/api/types";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { formatDateTime } from "@/lib/format/date";

const POLL_INTERVAL_MS = 60_000;

function notificationLabel(n: Notification, t: (key: TranslationKey) => string): string {
  if (n.payload?.summary) return n.payload.summary;
  const key = `notifications.type.${n.type}` as TranslationKey;
  const translated = t(key);
  // t() falls back to returning the key itself when missing — detect that and use the generic
  // catch-all instead of showing a raw "notifications.type.some_future_type" string.
  return translated === key ? t("notifications.type.generic") : translated;
}

export function NotificationBell() {
  const { t, locale } = useLocale();
  const router = useRouter();
  const pathname = usePathname();
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [markingAll, setMarkingAll] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await listNotifications();
      setNotifications(result.data);
      setUnreadCount(result.data.filter((n) => n.read_at === null).length);
    } catch {
      setError(t("notifications.loadFailed"));
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- t is stable enough for this purpose
  }, []);

  // Refresh on mount and whenever the route changes (refresh-on-navigation per the UX note).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load, pathname]);

  // Lightweight poll so a long-lived tab's badge doesn't go stale — not real-time push.
  useEffect(() => {
    const interval = setInterval(load, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [load]);

  // Close the dropdown on outside click.
  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, []);

  async function handleItemClick(n: Notification) {
    if (n.read_at === null) {
      // Optimistic update — the endpoint is idempotent, so a failure just means the next load()
      // re-syncs the true state rather than needing a rollback.
      setNotifications((prev) =>
        prev.map((item) => (item.id === n.id ? { ...item, read_at: new Date().toISOString() } : item)),
      );
      setUnreadCount((prev) => Math.max(0, prev - 1));
      try {
        await markNotificationRead(n.id);
      } catch {
        // best-effort; next load()/poll will reconcile
      }
    }
    // BRD v4 "Client Marketplace" — a new_inquiry_message notification carries a
    // conversation_id, not a project_id (an inquiry can exist before any project does), so it
    // routes to the staff inquiry thread instead.
    if (n.payload?.conversation_id) {
      setOpen(false);
      router.push(`/inquiries/${n.payload.conversation_id}`);
    } else if (n.payload?.project_id) {
      setOpen(false);
      router.push(`/projects/${n.payload.project_id}`);
    }
  }

  async function handleMarkAllRead() {
    setMarkingAll(true);
    try {
      await markAllNotificationsRead();
      setNotifications((prev) => prev.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })));
      setUnreadCount(0);
    } catch {
      // best-effort; next load()/poll will reconcile
    } finally {
      setMarkingAll(false);
    }
  }

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-label={t("nav.notifications")}
        className="relative rounded-md p-2 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
      >
        <BellIcon className="h-5 w-5" />
        {unreadCount > 0 ? (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white">
            {unreadCount > 99 ? "99+" : unreadCount}
          </span>
        ) : null}
      </button>

      {open ? (
        <div className="absolute right-0 z-50 mt-2 w-80 rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-800 dark:bg-zinc-900 rtl:left-0 rtl:right-auto">
          <div className="flex items-center justify-between border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
            <h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("notifications.title")}
            </h3>
            {unreadCount > 0 ? (
              <button
                type="button"
                onClick={handleMarkAllRead}
                disabled={markingAll}
                className="text-xs font-medium text-zinc-500 hover:text-zinc-900 disabled:opacity-60 dark:text-zinc-400 dark:hover:text-zinc-100"
              >
                {markingAll ? t("notifications.markingAllRead") : t("notifications.markAllRead")}
              </button>
            ) : null}
          </div>

          <div className="max-h-96 overflow-y-auto">
            {loading && notifications.length === 0 ? (
              <p className="px-3 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {t("common.loading")}
              </p>
            ) : error ? (
              <p className="px-3 py-6 text-center text-sm text-red-600 dark:text-red-400">{error}</p>
            ) : notifications.length === 0 ? (
              <p className="px-3 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {t("notifications.empty")}
              </p>
            ) : (
              <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
                {notifications.map((n) => (
                  <li key={n.id}>
                    <button
                      type="button"
                      onClick={() => handleItemClick(n)}
                      className={`flex w-full flex-col items-start gap-0.5 px-3 py-2.5 text-left rtl:text-right ${
                        n.read_at === null
                          ? "bg-blue-50 dark:bg-blue-950/30"
                          : "hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                      }`}
                    >
                      <span className="text-sm text-zinc-900 dark:text-zinc-50">
                        {notificationLabel(n, t)}
                      </span>
                      <span className="text-xs text-zinc-400">
                        {formatDateTime(n.sent_at ?? n.created_at, locale)}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}

function BellIcon({ className = "" }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden="true"
    >
      <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
      <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
    </svg>
  );
}
