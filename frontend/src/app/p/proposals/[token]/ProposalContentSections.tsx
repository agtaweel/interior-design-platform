/**
 * Renders the labeled content sections (cover/scope/exclusions/timeline/terms/payment_plan) plus
 * the BOQ items table and grand total for the S11 public proposal portal. Pure presentational —
 * no fetching, no auth-adjacent imports.
 */

import type { PublicProposalContent, PublicProposalItem } from "@/lib/api/publicTypes";
import { formatEGP } from "@/lib/format/currency";
import { directionFor } from "@/lib/i18n/textDirection";

const CONTENT_FIELDS: Array<{ key: keyof PublicProposalContent; label: string }> = [
  { key: "cover", label: "Introduction" },
  { key: "scope", label: "Scope of Work" },
  { key: "exclusions", label: "Exclusions" },
  { key: "timeline", label: "Timeline" },
  { key: "terms", label: "Terms" },
  { key: "payment_plan", label: "Payment Plan" },
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
  const fields = CONTENT_FIELDS.filter((f) => isMeaningful(content?.[f.key]));

  if (fields.length === 0) return null;

  return (
    <div className="space-y-5">
      {fields.map((field) => {
        const value = content![field.key] as string;
        return (
          <section key={field.key}>
            <h2 className="mb-1.5 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
              {field.label}
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
export function ProposalItemsTable({ items }: { items: PublicProposalItem[] }) {
  if (items.length === 0) {
    return <p className="text-sm text-zinc-500 dark:text-zinc-400">No line items on this proposal.</p>;
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
              {toNumber(item.quantity)} {item.unit} &times; {formatEGP(item.unit_price)}
            </span>
            <span className="shrink-0 font-semibold text-zinc-900 dark:text-zinc-50">
              {formatEGP(item.line_total)}
            </span>
          </div>
        </div>
      ))}
    </div>
  );
}

export function ProposalGrandTotal({ grandTotal }: { grandTotal: number | string }) {
  return (
    <div className="flex items-center justify-between rounded-lg bg-zinc-900 px-4 py-4 text-white dark:bg-zinc-100 dark:text-zinc-900">
      <span className="text-sm font-medium uppercase tracking-wide opacity-80">Total</span>
      <span className="text-xl font-semibold">{formatEGP(grandTotal)}</span>
    </div>
  );
}
