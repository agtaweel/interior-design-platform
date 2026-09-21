/**
 * BOQ (Sprint 2 — S07 BOQ Builder). Most of this file follows the same apiGetResource/
 * apiPostResource/apiPatchResource pattern as clients.ts/projects.ts. Two operations don't fit
 * that JSON-in/JSON-out shape and get bespoke fetch calls instead:
 *  - `exportBoq`: GET returns a CSV file stream, not a `{data: ...}` envelope — needs a blob
 *    response and the same Authorization/X-Organization-Id headers apiFetch would attach.
 *  - `importBoq`: POST is multipart/form-data (file upload) — must NOT set a JSON Content-Type
 *    header (the browser needs to set its own multipart boundary), so it can't go through
 *    apiFetch's JSON-body assumption either.
 */

import { apiFetch, apiGet, apiGetResource, apiPatchResource, apiPostResource, API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type {
  ApplyBoqTemplateResult,
  BoqCategory,
  BoqCategoryFormInput,
  BoqImportResult,
  BoqItem,
  BoqItemFormInput,
  BoqRoom,
  BoqRoomFormInput,
  BoqTemplateTree,
  BoqTree,
} from "@/lib/api/types";

export function getProjectBoq(projectId: string | number, includeArchived = false) {
  return apiGetResource<BoqTree>(
    `/projects/${projectId}/boq?include_archived=${includeArchived ? 1 : 0}`,
  );
}

/** GET /projects/{id}/rooms — list endpoint, but (like the rest of this API) returns the
 *  non-paginated `{data: [...]}` envelope, so apiGetResource's single-`data`-unwrap applies. */
export function getProjectRooms(projectId: string | number) {
  return apiGetResource<BoqRoom[]>(`/projects/${projectId}/rooms`);
}

export function createBoqRoom(projectId: string | number, input: BoqRoomFormInput) {
  return apiPostResource<BoqRoom>(`/projects/${projectId}/rooms`, input);
}

export function createBoqCategory(projectId: string | number, input: BoqCategoryFormInput) {
  return apiPostResource<BoqCategory>(`/projects/${projectId}/boq/categories`, input);
}

export function createBoqItem(projectId: string | number, input: BoqItemFormInput) {
  return apiPostResource<BoqItem>(`/projects/${projectId}/boq/items`, input);
}

export function updateBoqItem(itemId: string | number, input: Partial<BoqItemFormInput>) {
  return apiPatchResource<BoqItem>(`/boq/items/${itemId}`, input);
}

/** Archives (soft-deletes) the item — never a hard delete, see PROJECT_CONTEXT.md Sprint 2 API. */
export function archiveBoqItem(itemId: string | number) {
  return apiFetch<{ data: BoqItem }>(`/boq/items/${itemId}`, { method: "DELETE" }).then(
    (res) => res.data,
  );
}

export function getBoqTemplates() {
  return apiGet<{ data: BoqTemplateTree }>("/boq-templates/categories").then((res) => res.data);
}

export function applyBoqTemplate(projectId: string | number, templateCategoryId: string | number) {
  return apiPostResource<ApplyBoqTemplateResult>(
    `/projects/${projectId}/boq/apply-template/${templateCategoryId}`,
  );
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
 * Downloads GET /projects/{id}/boq/export and saves it as a file client-side. Uses a raw
 * `fetch` (not apiFetch) because the response is a CSV byte stream, not the JSON envelope
 * apiFetch parses — see file docblock.
 */
export async function exportBoq(projectId: string | number, projectCode?: string | null): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/projects/${projectId}/boq/export`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Export failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `boq-${projectCode ?? projectId}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

/**
 * Uploads a CSV file to POST /projects/{id}/boq/import. Uses a raw `fetch` with FormData (not
 * apiFetch) so the browser sets its own `multipart/form-data; boundary=...` Content-Type — see
 * file docblock.
 */
export async function importBoq(projectId: string | number, file: File): Promise<BoqImportResult> {
  const formData = new FormData();
  formData.append("file", file);

  const response = await fetch(`${API_BASE_URL}/projects/${projectId}/boq/import`, {
    method: "POST",
    headers: authHeaders(),
    body: formData,
  });

  const json = await response.json();

  if (!response.ok) {
    throw new Error(json?.error?.message ?? `Import failed with status ${response.status}.`);
  }

  return json.data as BoqImportResult;
}
