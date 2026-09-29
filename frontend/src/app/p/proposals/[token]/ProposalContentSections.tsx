/**
 * Renders the labeled content sections (cover/scope/exclusions/timeline/terms/payment_plan) plus
 * the BOQ items table and grand total for the S11 public proposal portal. Pure presentational —
 * no fetching, no auth-adjacent imports.
 */

import type { PublicProposalContent, PublicProposalItem } from "@/lib/api/publicTypes";
import { formatEGP } from "@/lib/format/currency";
import { directionFor } from "@/lib/i18n/textDirection";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

const CONTENT_FIELDS: Array<{ key: keyof PublicProposalContent; labelKey: TranslationKey }> = [
  { key: "cover", labelKey: "proposalContent.introduction" },
  { key: "scope", labelKey: "proposalContent.scope" },
  { key: "exclusions", labelKey: "proposalContent.exclusions" },
  { key: "timeline", labelKey: "proposalContent.timeline" },
  { key: "terms", labelKey: "proposalContent.terms" },
  { key: "payment_plan", labelKey: "proposalContent.paymentPlan" },
];

/** Skip a content field entirely if it's empty, whitespace-only, or a literal "not specified"
 *  placeholder — per PROJECT_CONTEXT.md/task spec, an empty labeled box is worse than omitting
 *  the section. */
function isMeaningful(value: string | null | undefined): value is string {
  if (!value) return false;
  const trimmed = value.trim();
  if (trimmed.length === 0) return false;
  if (trimmed.toLowerCase() === "not specified") return false;
  return true;
}

function toNumber(value: number | string): number {
  const n = typeof value === "string" ? Number(value) : value;
  return Number.isFinite(n) ? n : 0;
}

export function ProposalContentSections({ content }: { content: PublicProposalContent | null }) {
  const { t } = useLocale();
  const fields = CONTENT_FIELDS.filter((f) => isMeaningful(content?.[f.key]));

  if (fields.length === 0) return null;

  return (
    <div className="space-y-5">
      {fields.map((field) => {
        const value = content![field.key] as string;
        return (
          <section key={field.key}>
            <h2 className="mb-1.5 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
              {t(field.labelKey)}
            </h2>
            <p
              dir={directionFor(value)}
              className="whitespace-pre-wrap text-[15px] leading-relaxed text-zinc-800 dark:text-zinc-100"
            >
              {value}
            </p>
          </section>
        );
      })}
    </div>
  );
}

/**
 * Deliberately NOT a wide `<table>` with one column per field — at the PRD's primary target
 * width (375px, a phone opening a WhatsApp-shared link) a Description/Qty/Unit Price/Total
 * table forces horizontal scrolling to read the total, which is the one number a client most
 * needs to see without extra interaction. Instead: one stacked row per item (description on its
 * own line, "qty unit × unit price" and the line total on the line below), the common
 * mobile receipt/invoice pattern — every column of data is still present, just reflowed so
 * nothing is clipped at 375px width.
 */
export function ProposalItemsTable({
  items,
  currency = "EGP",
}: {
  items: PublicProposalItem[];
  /** Platform Readiness Review finding #06 — sourced from the proposal's own
   *  `organization.currency` (public payloads carry no AuthContext to read it from). */
  currency?: string;
}) {
  const { t, locale } = useLocale();

  if (items.length === 0) {
    return <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("proposalContent.noItems")}</p>;
  }

  return (
    <div className="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
      {items.map((item, index) => (
        <div key={index} className="px-3 py-3">
          <p
            dir={directionFor(item.description)}
            className="text-sm font-medium text-zinc-800 dark:text-zinc-100"
          >
            {item.description}
          </p>
          <div className="mt-1 flex items-baseline justify-between gap-3 text-sm">
            <span className="text-zinc-500 dark:text-zinc-400">
              {toNumber(item.quantity)} {item.unit} &times; {formatEGP(item.unit_price, locale, currency)}
            </span>
            <span className="shrink-0 font-semibold text-zinc-900 dark:text-zinc-50">
              {formatEGP(item.line_total, locale, currency)}
            </span>
          </div>
        </div>
      ))}
    </div>
  );
}

export function ProposalGrandTotal({
  grandTotal,
  currency = "EGP",
}: {
  grandTotal: number | string;
  currency?: string;
}) {
  const { t, locale } = useLocale();
  return (
    <div className="flex items-center justify-between rounded-lg bg-zinc-900 px-4 py-4 text-white dark:bg-zinc-100 dark:text-zinc-900">
      <span className="text-sm font-medium uppercase tracking-wide opacity-80">{t("proposalContent.total")}</span>
      <span className="text-xl font-semibold">{formatEGP(grandTotal, locale, currency)}</span>
    </div>
  );
}
