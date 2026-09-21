/**
 * Shared plain-number formatting (distinct from currency.ts, which is EGP-specific). Backend
 * decimal columns (e.g. `properties.area_m2`) arrive as fixed-precision strings like "180.00" —
 * this trims trailing zeros for display without introducing locale-specific grouping/rounding
 * beyond what a screen already does. Route any "trim a decimal string for display" need through
 * here rather than ad hoc `.replace(/\.?0+$/, "")` per screen.
 */

function toNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === "") return null;
  const n = typeof value === "string" ? Number(value) : value;
  return Number.isFinite(n) ? n : null;
}

/**
 * Formats a decimal amount trimming insignificant trailing zeros, e.g. `"180.00"` -> `"180"`,
 * `"180.50"` -> `"180.5"`, `180.5` -> `"180.5"`. Returns `null` for null/undefined/non-numeric
 * input so callers can decide their own "N/A" fallback.
 */
export function formatTrimmedNumber(value: number | string | null | undefined): string | null {
  const n = toNumber(value);
  if (n === null) return null;
  // Up to 2 decimal places (matches the precision of the decimal columns this formats), with
  // trailing zeros/decimal point stripped.
  return n.toFixed(2).replace(/\.?0+$/, "");
}

/** Formats a property's area in square metres, e.g. `formatArea("180.00")` -> `"180 m²"`. */
export function formatArea(value: number | string | null | undefined): string | null {
  const trimmed = formatTrimmedNumber(value);
  return trimmed === null ? null : `${trimmed} m²`;
}
