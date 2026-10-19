/**
 * WhatsApp click-to-chat helpers for sharing approval links. No WhatsApp Business API — this
 * only builds a wa.me URL the staff member opens themselves, which keeps the locked "links are
 * shared manually by staff" product decision (PROJECT_CONTEXT.md §5) intact.
 */

/**
 * Turns a stored phone number into the international digits wa.me expects. Numbers are entered
 * free-form and the platform's market is Egypt, so a local 11-digit mobile ("01xxxxxxxxx")
 * gets the 20 country code; "+20…" / "0020…" are normalised to the same. Returns null when
 * there's nothing usable, so callers fall back to letting WhatsApp pick the contact.
 */
export function toWhatsAppNumber(phone: string | null | undefined): string | null {
  if (!phone) return null;
  let digits = phone.replace(/\D/g, "");
  if (digits.startsWith("00")) digits = digits.slice(2);
  if (digits.length === 11 && digits.startsWith("01")) digits = `2${digits}`;
  return digits.length >= 8 ? digits : null;
}

/** wa.me link with the message prefilled; without a number WhatsApp asks who to send it to. */
export function whatsAppUrl(message: string, phone?: string | null): string {
  const number = toWhatsAppNumber(phone);
  return `https://wa.me/${number ?? ""}?text=${encodeURIComponent(message)}`;
}

/** Fills `{key}` placeholders in a dictionary template. */
export function fillTemplate(template: string, values: Record<string, string>): string {
  return Object.entries(values).reduce((text, [key, value]) => text.split(`{${key}}`).join(value), template);
}
