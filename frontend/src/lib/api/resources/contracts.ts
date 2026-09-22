/**
 * Contracts (Sprint 5 — S12 Contract). Follows the same apiGetResource/apiPostResource/
 * apiPatchResource pattern as proposals.ts. One bespoke fetch call for the PDF, matching boq.ts's
 * exportBoq — GET /contracts/{id}/pdf is internal/authenticated (unlike the public proposal PDF
 * route, there is no public contract-viewing surface at all), so it can't be a plain `<a href>`;
 * the browser needs the Authorization/X-Organization-Id headers attached.
 *
 * Immutability rule (see docs/PROJECT_CONTEXT.md Sprint 5): contract_value/proposal_version_id
 * are permanently locked at creation. `ContractUpdateInput` has no fields for them at all, so
 * this file never even gives a caller the option of sending them — the backend would reject
 * either with 422 `prohibited` regardless (verified live).
 *
 * `GET /projects/{id}/contracts` now exists (returns 0, 1, or more contracts for the project,
 * ordered by created_at) and the 409 CONTRACT_ALREADY_EXISTS response from the from-proposal
 * conversion now carries `details.contract_id`. The Contract tab page uses both to resolve
 * state without any client-side caching.
 */

import { apiGetResource, apiPatchResource, apiPostResource, API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type { Contract, ContractUpdateInput } from "@/lib/api/types";

/** POST /projects/{id}/contracts/from-proposal/{proposalId} — creates the contract; this call
 *  itself IS the signing act (signed_at = now() server-side, no separate signature step). Fails
 *  409 PROPOSAL_NOT_APPROVED if the source version isn't approved, 409 CONTRACT_ALREADY_EXISTS
 *  if one was already created for it (see file docblock for why the frontend can't check this
 *  ahead of time). */
export function createContractFromProposal(
  projectId: string | number,
  proposalId: string | number,
) {
  return apiPostResource<Contract>(
    `/projects/${projectId}/contracts/from-proposal/${proposalId}`,
  );
}

/** GET /contracts/{id} — full detail. */
export function getContract(contractId: string | number) {
  return apiGetResource<Contract>(`/contracts/${contractId}`);
}

/** GET /projects/{id}/contracts — 0, 1, or more contracts for the project, ordered by
 *  created_at. In practice there's at most one today (proposal_version_id is DB-unique), but
 *  the endpoint deliberately returns an array rather than assuming exactly one. */
export function getProjectContracts(projectId: string | number) {
  return apiGetResource<Contract[]>(`/projects/${projectId}/contracts`);
}

/** PATCH /contracts/{id} — updates start_date/end_date/terms_json only (see file docblock). */
export function updateContract(contractId: string | number, input: ContractUpdateInput) {
  return apiPatchResource<Contract>(`/contracts/${contractId}`, input);
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
 * Downloads GET /contracts/{id}/pdf and saves it client-side. Uses a raw `fetch` (not apiFetch)
 * because the response is a PDF byte stream, not the JSON envelope apiFetch parses — same
 * pattern as boq.ts's exportBoq/importBoq.
 */
export async function downloadContractPdf(
  contractId: string | number,
  contractNo?: string | null,
): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/contracts/${contractId}/pdf`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Download failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `${contractNo ?? `contract-${contractId}`}.pdf`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
