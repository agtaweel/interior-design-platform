/**
 * Single source of truth for money formatting across the app (per PROJECT_CONTEXT.md: "Money:
 * decimal types only, never floating point. EGP formatting..."). Always route money display
 * through this — no ad hoc `.toFixed(2)` or `${amount} EGP` in screens.
 *
 * Backend money fields arrive as numbers (or numeric strings from decimal columns), so this
 * accepts both and normalizes.
 */

export type Locale = "en" | "ar";

const INTL_LOCALE: Record<Locale, string> = {
  en: "en-EG",
  ar: "ar-EG",
};

function toNumber(amount: number | string | null | undefined): number {
  if (amount === null || amount === undefined) return 0;
  const n = typeof amount === "string" ? Number(amount) : amount;
  return Number.isFinite(n) ? n : 0;
}

/**
 * Formats a monetary amount as EGP, e.g. `formatEGP(12500)` -> "EGP 12,500.00" (en) or the
 * Arabic-numeral/RTL equivalent for `ar`.
 */
export function formatEGP(
  amount: number | string | null | undefined,
  locale: Locale = "en",
): string {
  return new Intl.NumberFormat(INTL_LOCALE[locale], {
    style: "currency",
    currency: "EGP",
    currencyDisplay: "code",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(toNumber(amount));
}

/** Renders a placeholder dash for money fields that have no data yet (e.g. Sprint 1 stubs). */
export function formatEGPOrDash(
  amount: number | string | null | undefined,
  locale: Locale = "en",
): string {
  if (amount === null || amount === undefined) return "—";
  return formatEGP(amount, locale);
}
