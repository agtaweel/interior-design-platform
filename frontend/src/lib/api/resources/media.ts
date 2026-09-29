/**
 * Project attachments (Documents tab). Follows the same apiGetResource pattern as other
 * resource files for the plain JSON list endpoint. Upload/preview don't fit that JSON-in/
 * JSON-out shape and get bespoke fetch calls instead, exactly like payments.ts's
 * recordPayment/downloadPaymentReceipt:
 *  - `uploadProjectMedia`: POST is multipart/form-data (`collection`, `file`, optional
 *    `caption`) — must NOT set a JSON Content-Type header (the browser sets its own multipart
 *    boundary).
 *  - `getMediaFileUrl`/`fetchMediaBlob`: GET /media/{id}/file streams the uploaded file, not a
 *    `{data: ...}` envelope — an authenticated, tenant-checked route (not a public/pre-signed
 *    URL), so it needs the Authorization/X-Organization-Id headers attached via a raw `fetch`
 *    and consumed as a blob (for inline `<img>` previews) or saved client-side (download).
 */

import { apiGet, apiGetResource, apiPostResource, API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type { GalleryMedia, Paginated, ProjectMedia, ProjectMediaCollection } from "@/lib/api/types";

/** GET /projects/{id}/media — all attachments across all three collections, newest first. */
export function getProjectMedia(projectId: string | number) {
  return apiGetResource<ProjectMedia[]>(`/projects/${projectId}/media`);
}

/** GET /media — the org-wide "Media" gallery across every project, newest first. Optional
 *  `collection`/`projectId` filters narrow it, matching the query params
 *  ProjectMediaController::galleryIndex() accepts. Returns the raw paginated envelope (not
 *  apiGetResource) since list endpoints in this app always expose `meta`/`links` alongside
 *  `data` — see client.ts's docblock on why apiFetch never unwraps `.data` unconditionally. */
export function getOrganizationMedia(filters?: {
  collection?: ProjectMediaCollection;
  projectId?: string | number;
}) {
  const params = new URLSearchParams();
  if (filters?.collection) params.set("collection", filters.collection);
  if (filters?.projectId) params.set("project_id", String(filters.projectId));
  const query = params.toString();

  return apiGet<Paginated<GalleryMedia>>(`/media${query ? `?${query}` : ""}`);
}

function authHeaders(): HeadersInit {
  const headers: Record<string, string> = {};
  const token = getStoredToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const organizationId = getStoredOrganizationId();
  if (organizationId) headers["X-Organization-Id"] = organizationId;
  return headers;
}

/**
 * Uploads a file via POST /projects/{id}/media. Uses a raw `fetch` with FormData (not
 * apiFetch) so the browser sets its own multipart boundary — see file docblock. Mirrors
 * payments.ts's recordPayment: throws a plain `Error` (not `ApiError`) since this bypasses
 * apiFetch entirely.
 */
export async function uploadProjectMedia(
  projectId: string | number,
  input: { collection: ProjectMediaCollection; file: File; caption?: string },
): Promise<ProjectMedia> {
  const formData = new FormData();
  formData.append("collection", input.collection);
  formData.append("file", input.file);
  if (input.caption) formData.append("caption", input.caption);

  const response = await fetch(`${API_BASE_URL}/projects/${projectId}/media`, {
    method: "POST",
    headers: authHeaders(),
    body: formData,
  });

  const json = await response.json();

  if (!response.ok) {
    throw new Error(json?.error?.message ?? `Upload failed with status ${response.status}.`);
  }

  return json.data as ProjectMedia;
}

/** DELETE /media/{id}. No response body (204) — mirrors StorePaymentRequest-adjacent
 *  mutation endpoints that don't return a resource on delete. */
export async function deleteProjectMedia(mediaId: string | number): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/media/${mediaId}`, {
    method: "DELETE",
    headers: authHeaders(),
  });

  if (!response.ok) {
    const json = await response.json().catch(() => null);
    throw new Error(json?.error?.message ?? `Delete failed with status ${response.status}.`);
  }
}

/**
 * Fetches GET /media/{id}/file as a blob and returns an object URL for inline preview
 * (`<img src>`) — the route is authenticated, so a plain `<img src="/media/1/file">` can't
 * attach the Authorization header itself. Callers must revoke the returned URL
 * (`URL.revokeObjectURL`) when done with it (see MediaThumbnail's cleanup effect).
 */
export async function fetchMediaObjectUrl(mediaId: string | number): Promise<string> {
  const response = await fetch(`${API_BASE_URL}/media/${mediaId}/file`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Download failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  return URL.createObjectURL(blob);
}

/** Downloads GET /media/{id}/file and saves it client-side with its real filename, forcing a
 *  save regardless of the response's inline Content-Disposition — same pattern as
 *  payments.ts's downloadPaymentReceipt. */
export async function downloadProjectMedia(media: ProjectMedia): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/media/${media.id}/file`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Download failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = media.file_name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
