/**
 * Egyptian phone number formatting. Egyptian mobile numbers are 11 digits starting with 01
 * (e.g. 01012345678) or +20 followed by 10 digits dropping the leading 0 (e.g. +201012345678).
 * This is a display formatter only — it does not validate/reject malformed input, it just
 * renders best-effort and falls back to the raw string.
 */

export function formatEgyptianPhone(phone: string | null | undefined): string {
  if (!phone) return "—";

  const digits = phone.replace(/[^\d]/g, "");

  // +20XXXXXXXXXX (12 digits after stripping the +) -> "+20 1X XXXX XXXX"
  if (digits.length === 12 && digits.startsWith("20")) {
    const national = digits.slice(2);
    return `+20 ${national.slice(0, 2)} ${national.slice(2, 6)} ${national.slice(6)}`;
  }

  // 01XXXXXXXXX (11 digits, local format) -> "010 1234 5678"
  if (digits.length === 11 && digits.startsWith("0")) {
    return `${digits.slice(0, 3)} ${digits.slice(3, 7)} ${digits.slice(7)}`;
  }

  // Landline or anything else we don't specifically recognize: return as typed.
  return phone;
}
