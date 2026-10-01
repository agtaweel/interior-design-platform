import { apiGet, apiGetResource } from "@/lib/api/client";
import type { Paginated, PublicOrganizationDetail, PublicOrganizationSummary } from "@/lib/api/types";

/**
 * BRD v4 "Client Marketplace" — fully public browse/detail reads, no token or login of any kind
 * (unlike every other `/public/...` resource in this app). `skipAuth`/`skipOrganization` here
 * aren't strictly needed (there's no token to skip for an anonymous visitor either way), but set
 * for the same explicitness every other public resource file in this app already uses.
 */

export function listMarketplaceOrganizations(params?: { q?: string; page?: number }) {
  const query = new URLSearchParams();
  if (params?.q) query.set("q", params.q);
  if (params?.page) query.set("page", String(params.page));
  const qs = query.toString();

  return apiGet<Paginated<PublicOrganizationSummary>>(
    `/public/marketplace/organizations${qs ? `?${qs}` : ""}`,
    { skipAuth: true, skipOrganization: true },
  );
}

export function getMarketplaceOrganization(organizationId: string) {
  return apiGetResource<PublicOrganizationDetail>(
    `/public/marketplace/organizations/${organizationId}`,
    { skipAuth: true, skipOrganization: true },
  );
}

export function marketplaceOrganizationMediaUrl(organizationId: string | number, mediaId: string | number) {
  const base = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";
  return `${base}/public/marketplace/organizations/${organizationId}/media/${mediaId}/file`;
}
