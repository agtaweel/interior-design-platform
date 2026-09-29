/**
 * Handover (BRD S20: final approval, warranty, completion document). One per project.
 */

import { apiGetResource, apiPostResource, API_BASE_URL, ApiError } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type { Handover, HandoverFormInput } from "@/lib/api/types";

/** Returns null (not a thrown error) for the common "no handover yet" 404 — callers treat that
 *  as an empty state, not a load failure. */
export async function getHandover(projectId: string | number): Promise<Handover | null> {
  try {
    return await apiGetResource<Handover>(`/projects/${projectId}/handover`);
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) return null;
    throw err;
  }
}

export function createHandover(projectId: string | number, input: HandoverFormInput) {
  return apiPostResource<Handover>(`/projects/${projectId}/handover`, input);
}

function authHeaders(): HeadersInit {
  const headers: Record<string, string> = {};
  const token = getStoredToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const organizationId = getStoredOrganizationId();
  if (organizationId) headers["X-Organization-Id"] = organizationId;
  return headers;
}

export async function downloadHandoverPdf(projectId: string | number): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/projects/${projectId}/handover/pdf`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Download failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `handover-${projectId}.pdf`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
