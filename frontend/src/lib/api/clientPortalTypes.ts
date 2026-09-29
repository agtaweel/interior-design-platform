/**
 * Types for the BRD v3 §17 Client Portal (GET /public/client-portal/{token}/...). Mirrors
 * PublicClientPortalController's responses exactly — see that class's docblock for the
 * client-safe leak-prevention boundary this project depends on: none of these types carry a
 * cost/margin/supplier field, matching the backend's own "never expose" list (supplier_cost,
 * markup_percentage, markup_amount, supervision_percentage, supervision_amount, profit,
 * internal_pricing_rule_id) plus quoted_cost/committed_cost/actual_cost/gross_profit/
 * margin_percent, none of which this controller ever computes or returns.
 */

export interface ClientPortalOverview {
  project: {
    code: string;
    name: string;
    status: string;
    start_date: string | null;
    target_end_date: string | null;
  };
  organization: { currency: string } | null;
  financials: {
    value: number | string;
    collected: number | string;
    outstanding: number | string;
  };
  counts: {
    proposals: number;
    change_orders: number;
    payments: number;
  };
}

export interface ClientPortalProposalSummary {
  id: number | string;
  version_no: number;
  status: string;
  sent_at: string | null;
  approved_at: string | null;
  grand_total: number | string;
}

export interface ClientPortalContract {
  id: number | string;
  contract_no: string;
  status: string;
  contract_value: number | string;
  signed_at: string | null;
  start_date: string | null;
  end_date: string | null;
}

export interface ClientPortalPayment {
  id: number | string;
  amount: number | string;
  payment_method: string;
  paid_at: string;
  reference: string | null;
  has_receipt: boolean;
  notes: string | null;
}

export interface ClientPortalChangeOrderItem {
  description: string;
  quantity: number | string;
  unit: string;
  old_unit_price: number | string | null;
  new_unit_price: number | string | null;
  line_delta: number | string;
}

export interface ClientPortalChangeOrder {
  number: string;
  status: string;
  reason: string;
  timeline_delta_days: number | string | null;
  price_delta: number | string;
  items: ClientPortalChangeOrderItem[];
  sent_at: string | null;
  approved_at: string | null;
}

export interface ClientPortalMediaItem {
  id: number | string;
  collection: string;
  file_name: string;
  mime_type: string;
  size: number;
  caption: string | null;
  uploaded_by: string | null;
  created_at: string;
}
