/**
 * Renders the reason, timeline impact, price impact, and line-items table for the S14 public
 * Change Order approval page. Pure presentational — no fetching, no auth-adjacent imports.
 * Sibling to `/p/proposals/[token]/ProposalContentSections.tsx`; follows the same mobile-first
 * "stacked row per item" pattern for the same reason (a 375px phone screen opening a
 * WhatsApp-shared link shouldn't need horizontal scrolling to read the number that matters).
 */

import type { PublicChangeOrderItem } from "@/lib/api/publicChangeOrderTypes";
import { formatEGP } from "@/lib/format/currency";
import { directionFor } from "@/lib/i18n/textDirection";

function toNumber(value: number | string | null | undefined): number {
  if (value === null || value === undefined) return 0;
  const n = typeof value === "string" ? Number(value) : value;
  return Number.isFinite(n) ? n : 0;
}

/** Prefixes an explicit "+"/"-" sign onto an amount so a price increase vs. decrease reads
 *  unambiguously at a glance — `formatEGP` alone renders a negative with a leading minus but a
 *  positive with none, which isn't distinct enough for a number this consequential. */
function formatSignedEGP(amount: number | string, currency: string): string {
  const n = toNumber(amount);
  const magnitude = formatEGP(Math.abs(n), "en", currency);
  if (n === 0) return magnitude;
  return n > 0 ? `+${magnitude}` : `-${magnitude}`;
}

/** "+5 days" / "-3 days" / "No timeline impact" — per the task spec's exact examples. */
export function formatTimelineImpact(days: number | null): string {
  if (days === null || days === undefined || days === 0) return "No timeline impact";
  const unit = Math.abs(days) === 1 ? "day" : "days";
  return days > 0 ? `+${days} ${unit}` : `${days} ${unit}`;
}

export function ChangeOrderReason({ reason }: { reason: string }) {
  if (!reason || !reason.trim()) return null;
  return (
    <section>
      <h2 className="mb-1.5 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
        Reason
      </h2>
      <p
        dir={directionFor(reason)}
        className="whitespace-pre-wrap text-[15px] leading-relaxed text-zinc-800 dark:text-zinc-100"
      >
        {reason}
      </p>
    </section>
  );
}

/**
 * Price impact prominently displayed, per the task spec — this is the single number a client
 * most needs to register. Color-coded so the direction is legible without reading the sign:
 * amber for an increase (the client owes more), green for a decrease/credit, neutral for zero.
 */
export function ChangeOrderPriceImpact({
  priceDelta,
  currency = "EGP",
}: {
  priceDelta: number | string;
  currency?: string;
}) {
  const n = toNumber(priceDelta);
  const tone =
    n > 0
      ? "bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
      : n < 0
        ? "bg-green-50 text-green-900 dark:bg-green-950/40 dark:text-green-300"
        : "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900";

  return (
    <div className={`flex items-center justify-between rounded-lg px-4 py-4 ${tone}`}>
      <span className="text-sm font-medium uppercase tracking-wide opacity-80">Price Impact</span>
      <span className="text-xl font-semibold">{formatSignedEGP(priceDelta, currency)}</span>
    </div>
  );
}

export function ChangeOrderTimelineImpact({ timelineDeltaDays }: { timelineDeltaDays: number | null }) {
  return (
    <div className="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-800">
      <span className="text-sm font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
        Timeline Impact
      </span>
      <span className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
        {formatTimelineImpact(timelineDeltaDays)}
      </span>
    </div>
  );
}

/** Renders one item's price change: added (new price only), removed (old price, struck
 *  through), or modified (old -> new). Which case applies is inferred from which of
 *  `old_unit_price`/`new_unit_price` is present, since the public payload has no `action`
 *  field (see publicChangeOrderTypes.ts's docblock). */
function ItemPriceChange({ item, currency }: { item: PublicChangeOrderItem; currency: string }) {
  const hasOld = item.old_unit_price !== null && item.old_unit_price !== undefined;
  const hasNew = item.new_unit_price !== null && item.new_unit_price !== undefined;

  if (hasOld && hasNew) {
    return (
      <span className="text-zinc-500 dark:text-zinc-400">
        {formatEGP(item.old_unit_price, "en", currency)} <span aria-hidden="true">&rarr;</span>{" "}
        {formatEGP(item.new_unit_price, "en", currency)}
      </span>
    );
  }
  if (hasNew) {
    return (
      <span className="text-zinc-500 dark:text-zinc-400">
        New item &middot; {formatEGP(item.new_unit_price, "en", currency)}
      </span>
    );
  }
  if (hasOld) {
    return (
      <span className="text-zinc-500 dark:text-zinc-400">
        Removed &middot; <span className="line-through">{formatEGP(item.old_unit_price, "en", currency)}</span>
      </span>
    );
  }
  return null;
}

export function ChangeOrderItemsTable({
  items,
  currency = "EGP",
}: {
  items: PublicChangeOrderItem[];
  currency?: string;
}) {
  if (items.length === 0) {
    return <p className="text-sm text-zinc-500 dark:text-zinc-400">No line items on this change order.</p>;
  }

  return (
    <div className="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
      {items.map((item, index) => {
        const lineDelta = toNumber(item.line_delta);
        return (
          <div key={index} className="px-3 py-3">
            <p
              dir={directionFor(item.description)}
              className="text-sm font-medium text-zinc-800 dark:text-zinc-100"
            >
              {item.description}
            </p>
            <div className="mt-1 flex items-baseline justify-between gap-3 text-sm">
              <span className="text-zinc-500 dark:text-zinc-400">
                {toNumber(item.quantity)} {item.unit}
              </span>
              <span
                className={`shrink-0 font-semibold ${
                  lineDelta > 0
                    ? "text-amber-700 dark:text-amber-400"
                    : lineDelta < 0
                      ? "text-green-700 dark:text-green-400"
                      : "text-zinc-900 dark:text-zinc-50"
                }`}
              >
                {formatSignedEGP(item.line_delta, currency)}
              </span>
            </div>
            <div className="mt-0.5 text-sm">
              <ItemPriceChange item={item} currency={currency} />
            </div>
          </div>
        );
      })}
    </div>
  );
}
