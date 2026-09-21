/**
 * Egyptian date formatting (Africa/Cairo timezone, Gregorian calendar — Egypt uses the
 * Gregorian calendar for business/legal dates, unlike some other Arabic-locale defaults).
 * Single source of truth: route all date display through this rather than ad hoc
 * `new Date(...).toLocaleDateString()` per screen.
 */

import type { Locale } from "@/lib/format/currency";

const INTL_LOCALE: Record<Locale, string> = {
  en: "en-EG",
  ar: "ar-EG",
};

/** e.g. "21 Sep 2026" (en) / equivalent Arabic (ar). */
export function formatDate(
  date: string | Date | null | undefined,
  locale: Locale = "en",
): string {
  if (!date) return "—";
  const d = typeof date === "string" ? new Date(date) : date;
  if (Number.isNaN(d.getTime())) return "—";

  return new Intl.DateTimeFormat(INTL_LOCALE[locale], {
    calendar: "gregory",
    timeZone: "Africa/Cairo",
    day: "2-digit",
    month: "short",
    year: "numeric",
  }).format(d);
}

/** e.g. "21 Sep 2026, 14:05" — for activity/audit timestamps. */
export function formatDateTime(
  date: string | Date | null | undefined,
  locale: Locale = "en",
): string {
  if (!date) return "—";
  const d = typeof date === "string" ? new Date(date) : date;
  if (Number.isNaN(d.getTime())) return "—";

  return new Intl.DateTimeFormat(INTL_LOCALE[locale], {
    calendar: "gregory",
    timeZone: "Africa/Cairo",
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(d);
}
