/**
 * Types for the BRD v3 §5/§20 Platform Owner analytics tier (GET /platform/...). Mirrors
 * PlatformAnalyticsController exactly. Cross-tenant by design — these are the only endpoints in
 * this app not scoped to a single organization.
 */

export interface PlatformFinancials {
  obligation: number | string;
  paid: number | string;
  outstanding: number | string;
  actual_cost: number | string;
  gross_profit: number | string | null;
  margin_percent: string | null;
}

export interface PlatformSummary {
  organizations_count: number;
  projects_count: number;
  projects_by_status: Record<string, number>;
  users_count: number;
  financials: PlatformFinancials;
}

export interface PlatformOrganizationSummary {
  id: number | string;
  name: string;
  legal_name: string | null;
  currency: string;
  members_count: number;
  projects_count: number;
  created_at: string;
}

export interface PlatformOrganizationDetail extends PlatformOrganizationSummary {
  projects_by_status: Record<string, number>;
  financials: PlatformFinancials;
}
