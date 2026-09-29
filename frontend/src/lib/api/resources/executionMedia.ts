/**
 * Shared photo-fetching helper for Task/SiteReport photos (both stream through
 * GET /execution-media/{id}/file — ExecutionMediaController). Mirrors
 * lib/api/resources/media.ts's fetchMediaObjectUrl exactly: an authenticated route, so a plain
 * `<img src>` can't attach the Authorization header — fetch as a blob and hand back an object
 * URL instead. Callers must revoke it when done (see MediaLightbox-adjacent usage).
 */

import { API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";

function authHeaders(): HeadersInit {
  const headers: Record<string, string> = {};
  const token = getStoredToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const organizationId = getStoredOrganizationId();
  if (organizationId) headers["X-Organization-Id"] = organizationId;
  return headers;
}

export async function fetchExecutionMediaObjectUrl(mediaId: string | number): Promise<string> {
  const response = await fetch(`${API_BASE_URL}/execution-media/${mediaId}/file`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Download failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  return URL.createObjectURL(blob);
}
