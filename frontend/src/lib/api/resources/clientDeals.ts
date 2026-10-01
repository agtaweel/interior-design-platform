import { apiGetResource, apiPostResource } from "@/lib/api/client";
import { getStoredClientToken } from "@/lib/auth/clientTokenStorage";
import type { ClientDealSummary } from "@/lib/api/types";
import type { PublicApproveResult, PublicProposalData, PublicRequestChangesResult } from "@/lib/api/publicTypes";

/**
 * BRD v4 "Client Marketplace" Phase C — a marketplace client's own deals (the existing Proposal
 * system, relabeled). GET /client/deals/{id} returns the exact same PublicProposalResource shape
 * as the anonymous public portal, so this reuses PublicProposalData rather than inventing a new
 * type for an identical payload. approve()/requestChanges() take only an optional/required
 * `comment` — no `name` (comes from the authenticated ClientUser) and no `otp` (skipped
 * entirely for this logged-in flow — see config('fitout.marketplace_deal_requires_otp')'s
 * docblock on the backend).
 */

function clientAuthHeader(): HeadersInit {
  const token = getStoredClientToken();
  return token ? { Authorization: `Bearer ${token}` } : {};
}

function clientGet<T>(path: string) {
  return apiGetResource<T>(path, { skipAuth: true, skipOrganization: true, headers: clientAuthHeader() });
}

function clientPost<T>(path: string, body?: unknown) {
  return apiPostResource<T>(path, body, { skipAuth: true, skipOrganization: true, headers: clientAuthHeader() });
}

export function listClientDeals() {
  return clientGet<ClientDealSummary[]>("/client/deals");
}

export function getClientDeal(proposalId: string | number) {
  return clientGet<PublicProposalData>(`/client/deals/${proposalId}`);
}

export function approveClientDeal(proposalId: string | number, comment?: string) {
  return clientPost<PublicApproveResult>(`/client/deals/${proposalId}/approve`, comment ? { comment } : undefined);
}

export function requestClientDealChanges(proposalId: string | number, comment: string) {
  return clientPost<PublicRequestChangesResult>(`/client/deals/${proposalId}/request-changes`, { comment });
}
