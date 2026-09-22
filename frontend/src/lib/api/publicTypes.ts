/**
 * Types for the S11 public client proposal portal (GET/POST /public/proposals/{token}...).
 * Mirrors `PublicProposalResource`/`ProposalPresenter` on the backend exactly (see
 * backend/app/Http/Resources/PublicProposalResource.php) — kept separate from
 * `lib/api/types.ts`'s internal `ProposalVersion`/`ProposalItem` shapes on purpose: the public
 * payload is a deliberately narrower, hand-scrubbed projection (no `id`, no
 * `source_boq_item_id`, no cost/margin fields, no subtotal/markup/fees/discount — only
 * `grand_total`), and giving it its own types makes that narrowness visible at the type level
 * instead of relying on everyone remembering not to reach for a wider internal field that
 * happens to share a name.
 */

export type PublicProposalStatus = "draft" | "sent" | "approved" | "changes_requested";

export interface PublicOrganization {
  id: number | string;
  name: string;
  email: string | null;
  phone: string | null;
  currency: string;
  logo_url: string | null;
  legal_name: string | null;
}

export interface PublicProject {
  id: number | string;
  code: string;
  name: string;
}

export interface PublicClient {
  id: number | string;
  name: string;
  email: string | null;
  phone: string | null;
}

export interface PublicProperty {
  id: number | string;
  type: string | null;
  compound: string | null;
  address: string | null;
}

export interface PublicProposalMeta {
  version_no: number;
  status: PublicProposalStatus;
  sent_at: string | null;
  approved_at: string | null;
}

/** Free-form editorial content — every field optional/nullable, same convention as the internal
 *  `ProposalContent` type. Screens should skip rendering any field that's empty/whitespace-only
 *  rather than showing an empty labeled box. */
export interface PublicProposalContent {
  cover?: string | null;
  scope?: string | null;
  exclusions?: string | null;
  timeline?: string | null;
  terms?: string | null;
  payment_plan?: string | null;
}

/** Client-shaped line item — deliberately has no `id`/`source_boq_item_id`/cost fields, matching
 *  exactly what `PublicProposalResource` serializes. */
export interface PublicProposalItem {
  description: string;
  quantity: number | string;
  unit: string;
  unit_price: number | string;
  line_total: number | string;
}

export interface PublicProposalPricing {
  grand_total: number | string;
}

/** GET /public/proposals/{token} response `data`. */
export interface PublicProposalData {
  organization: PublicOrganization | null;
  project: PublicProject;
  client: PublicClient | null;
  property: PublicProperty | null;
  proposal: PublicProposalMeta;
  content: PublicProposalContent | null;
  items: PublicProposalItem[];
  pricing: PublicProposalPricing;
}

/** POST /public/proposals/{token}/approve success response (200). */
export interface PublicApproveResult {
  status: "approved";
  approved_at: string;
  approval_id: number | string;
  contract_conversion_available: boolean;
}

/** POST /public/proposals/{token}/request-changes success response `data`. */
export interface PublicRequestChangesResult {
  status: "changes_requested";
}
