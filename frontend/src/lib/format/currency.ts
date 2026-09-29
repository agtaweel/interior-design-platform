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
 * Formats a monetary amount, e.g. `formatEGP(12500)` -> "EGP 12,500.00" (en) or the
 * Arabic-numeral/RTL equivalent for `ar`.
 *
 * Platform Readiness Review finding #06: `currency` used to be hardcoded to "EGP" here
 * regardless of which organization the amount actually belonged to. It's now a parameter —
 * "EGP" remains the default so every existing call site that doesn't pass one keeps working
 * unchanged, but callers that have the organization's real currency on hand (via
 * `useAuth().currentOrganizationCurrency`, or a public payload's own `organization.currency`)
 * should pass it through. The function is still named formatEGP rather than a currency-neutral
 * name to avoid a mechanical rename across every existing import in the app for what is, in
 * effect, an additive parameter — worth revisiting in a dedicated pass if this app becomes
 * currency-neutral in more than name.
 */
export function formatEGP(
  amount: number | string | null | undefined,
  locale: Locale = "en",
  currency: string = "EGP",
): string {
  return new Intl.NumberFormat(INTL_LOCALE[locale], {
    style: "currency",
    currency,
    currencyDisplay: "code",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(toNumber(amount));
}

/** Renders a placeholder dash for money fields that have no data yet (e.g. Sprint 1 stubs). */
export function formatEGPOrDash(
  amount: number | string | null | undefined,
  locale: Locale = "en",
  currency: string = "EGP",
): string {
  if (amount === null || amount === undefined) return "—";
  return formatEGP(amount, locale, currency);
}
