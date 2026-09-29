/**
 * Types for the S14 public Change Order approval page (GET/POST /public/change-orders/{token}...).
 * Mirrors `PublicChangeOrderResource` on the backend exactly (see
 * backend/app/Http/Resources/PublicChangeOrderResource.php) — kept in its own file, separate
 * from `publicTypes.ts` (the proposal portal's types), same "clearly separated per-feature"
 * convention the rest of this codebase uses (see `lib/api/resources/changeOrders.ts` vs
 * `lib/api/resources/proposals.ts` on the internal side).
 *
 * Unlike `PublicProposalData`, there is no `project`/`client`/`property` header info in this
 * payload — a change order references its project only internally, and the public view is
 * deliberately more minimal than the proposal one. The one exception is `organization.currency`
 * (Platform Readiness Review finding #06), added specifically so this page's money formatting
 * isn't stuck hardcoded to "EGP" — see PublicChangeOrderResource's docblock.
 *
 * `price_delta` and each item's `old_unit_price`/`new_unit_price`/`line_delta` ARE included and
 * meant to be shown to the client — unlike BOQ internal cost/margin fields, a price delta is
 * literally the commercial thing being approved here (PROJECT_CONTEXT.md Sprint 7 is explicit
 * about this). There is still no `boq_item_id`/internal linkage of any kind — that stays
 * server-side only.
 */

export type PublicChangeOrderStatus = "draft" | "sent" | "approved" | "rejected" | "applied";

/** Client-shaped line item. Which of `old_unit_price`/`new_unit_price` is present (rather than
 *  an explicit `action` field, which this endpoint never sends) is how the UI infers whether a
 *  line was added (`old_unit_price` null), removed (`new_unit_price` null), or modified (both
 *  present) — see backend/app/Http/Resources/ChangeOrderItemResource.php's internal counterpart
 *  for the full add/remove/modify model this projects from. */
export interface PublicChangeOrderItem {
  description: string;
  quantity: number | string;
  unit: string;
  old_unit_price: number | string | null;
  new_unit_price: number | string | null;
  line_delta: number | string;
}

/** GET /public/change-orders/{token} response `data`. */
export interface PublicChangeOrderData {
  number: string;
  status: PublicChangeOrderStatus;
  reason: string;
  timeline_delta_days: number | null;
  price_delta: number | string;
  items: PublicChangeOrderItem[];
  sent_at: string | null;
  approved_at: string | null;
  organization: { currency: string } | null;
}

/** POST /public/change-orders/{token}/approve success response (200, NOT `{data}`-wrapped —
 *  see PublicChangeOrderController::approve(), same flat-body convention as the proposal
 *  portal's approve endpoint). Deliberately has no `contract_conversion_available` field —
 *  that's a proposal-specific concept (Sprint 5 contract conversion), not applicable here. */
export interface PublicChangeOrderApproveResult {
  status: "approved";
  approved_at: string;
  approval_id: number | string;
}

/** POST /public/change-orders/{token}/reject success response `data`. */
export interface PublicChangeOrderRejectResult {
  status: "rejected";
}
