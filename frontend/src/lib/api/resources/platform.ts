/**
 * BRD v3 §5/§20 Platform Owner analytics tier. Uses the normal authenticated `apiGetResource`
 * (a valid Sanctum token IS required — see EnsurePlatformOwner) but these requests never carry
 * meaningful X-Organization-Id semantics: the backend routes them outside the `tenant`
 * middleware entirely, so any such header is simply ignored server-side.
 */

import { apiGetResource } from "@/lib/api/client";
import type {
  PlatformOrganizationDetail,
  PlatformOrganizationSummary,
  PlatformSummary,
} from "@/lib/api/platformTypes";

export function getPlatformSummary() {
  return apiGetResource<PlatformSummary>("/platform/analytics/summary");
}

export function getPlatformOrganizations() {
  return apiGetResource<PlatformOrganizationSummary[]>("/platform/organizations");
}

export function getPlatformOrganization(id: string | number) {
  return apiGetResource<PlatformOrganizationDetail>(`/platform/organizations/${id}`);
}
