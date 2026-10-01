/**
 * BRD v4 "Client Marketplace" — staff-side settings for the public listing profile. Deliberately
 * a separate file from organization.ts: these hit a different sub-resource
 * (/organizations/{id}/profile, not /organizations/{id}) with its own permission check
 * (UpdateOrganizationProfileRequest), even though both reuse Permissions::MANAGE_ORGANIZATION.
 */

import { apiGetResource, apiPatchResource, API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type { OrganizationProfileData } from "@/lib/api/types";

export function getOrganizationMarketplaceProfile(organizationId: string | number) {
  return apiGetResource<OrganizationProfileData>(`/organizations/${organizationId}/profile`);
}

export function updateOrganizationMarketplaceProfile(
  organizationId: string | number,
  input: Partial<OrganizationProfileData>,
) {
  return apiPatchResource<OrganizationProfileData>(`/organizations/${organizationId}/profile`, input);
}

function authHeaders(): HeadersInit {
  const headers: Record<string, string> = {};
  const token = getStoredToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const organizationId = getStoredOrganizationId();
  if (organizationId) headers["X-Organization-Id"] = organizationId;
  return headers;
}

export interface PortfolioMediaItem {
  id: number | string;
  file_name: string;
  mime_type: string;
}

export function listOrganizationPortfolioMedia(organizationId: string | number) {
  return apiGetResource<PortfolioMediaItem[]>(`/organizations/${organizationId}/portfolio`);
}

/** Mirrors media.ts's uploadProjectMedia — multipart, bespoke fetch (not apiFetch). */
export async function uploadOrganizationPortfolioMedia(
  organizationId: string | number,
  file: File,
): Promise<PortfolioMediaItem> {
  const formData = new FormData();
  formData.append("file", file);

  const response = await fetch(`${API_BASE_URL}/organizations/${organizationId}/portfolio`, {
    method: "POST",
    headers: authHeaders(),
    body: formData,
  });

  const json = await response.json();

  if (!response.ok) {
    throw new Error(json?.error?.message ?? `Upload failed with status ${response.status}.`);
  }

  return json.data as PortfolioMediaItem;
}

export async function deleteOrganizationPortfolioMedia(
  organizationId: string | number,
  mediaId: string | number,
): Promise<void> {
  const response = await fetch(
    `${API_BASE_URL}/organizations/${organizationId}/portfolio/${mediaId}`,
    { method: "DELETE", headers: authHeaders() },
  );

  if (!response.ok && response.status !== 204) {
    const json = await response.json().catch(() => null);
    throw new Error(json?.error?.message ?? `Delete failed with status ${response.status}.`);
  }
}
