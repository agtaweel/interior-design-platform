/**
 * BRD v3 §17 Client Portal — every public, token-authenticated read-only endpoint. Uses
 * `publicClient.ts` (NOT `client.ts`'s `apiFetch`), same reasoning as `publicProposals.ts`: no
 * login, no Sanctum session, no tenant header — the token in the URL path is the only
 * credential.
 */

import { publicGetResource, publicGetResourceWithOrg, publicPost } from "@/lib/api/publicClient";
import type {
  ClientPortalChangeOrder,
  ClientPortalContract,
  ClientPortalMediaItem,
  ClientPortalOverview,
  ClientPortalPayment,
  ClientPortalProposalSummary,
} from "@/lib/api/clientPortalTypes";
import type { PublicApproveResult, PublicProposalData, PublicRequestChangesResult } from "@/lib/api/publicTypes";
import type { PublicChangeOrderApproveResult, PublicChangeOrderRejectResult } from "@/lib/api/publicChangeOrderTypes";

const BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";

function base(token: string): string {
  return `/public/client-portal/${encodeURIComponent(token)}`;
}

export function getClientPortalOverview(token: string) {
  return publicGetResource<ClientPortalOverview>(base(token));
}

export function getClientPortalProposals(token: string) {
  return publicGetResourceWithOrg<ClientPortalProposalSummary[]>(`${base(token)}/proposals`);
}

export function getClientPortalProposal(token: string, proposalVersionId: string | number) {
  return publicGetResource<PublicProposalData>(
    `${base(token)}/proposals/${encodeURIComponent(String(proposalVersionId))}`,
  );
}

export function clientPortalProposalPdfUrl(token: string, proposalVersionId: string | number): string {
  return `${BASE_URL}${base(token)}/proposals/${encodeURIComponent(String(proposalVersionId))}/pdf`;
}

/** 404s (via a rejected promise) if the project has no contract yet — callers should treat that
 *  as "no contract" rather than a load error. */
export function getClientPortalContract(token: string) {
  return publicGetResourceWithOrg<ClientPortalContract>(`${base(token)}/contract`);
}

export function getClientPortalPayments(token: string) {
  return publicGetResourceWithOrg<ClientPortalPayment[]>(`${base(token)}/payments`);
}

export function getClientPortalChangeOrders(token: string) {
  return publicGetResourceWithOrg<ClientPortalChangeOrder[]>(`${base(token)}/change-orders`);
}

// --- Decisions inside the portal (P3.1) — same response shapes as the per-document links:
// approve returns the flat body, request-changes/reject the `{data}` envelope.

export function approveClientPortalProposal(
  token: string,
  proposalVersionId: string | number,
  input: { name: string; comment?: string; otp: string },
) {
  return publicPost<PublicApproveResult>(`${base(token)}/proposals/${encodeURIComponent(String(proposalVersionId))}/approve`, input);
}

export function requestClientPortalProposalChanges(
  token: string,
  proposalVersionId: string | number,
  input: { name: string; comment: string },
) {
  return publicPost<{ data: PublicRequestChangesResult }>(
    `${base(token)}/proposals/${encodeURIComponent(String(proposalVersionId))}/request-changes`,
    input,
  ).then((res) => res.data);
}

export function approveClientPortalChangeOrder(token: string, number: string, input: { name: string; comment?: string; otp: string }) {
  return publicPost<PublicChangeOrderApproveResult>(`${base(token)}/change-orders/${encodeURIComponent(number)}/approve`, input);
}

export function rejectClientPortalChangeOrder(token: string, number: string, input: { name: string; comment: string }) {
  return publicPost<{ data: PublicChangeOrderRejectResult }>(
    `${base(token)}/change-orders/${encodeURIComponent(number)}/reject`,
    input,
  ).then((res) => res.data);
}

export function getClientPortalMedia(token: string) {
  return publicGetResource<ClientPortalMediaItem[]>(`${base(token)}/media`);
}

export function clientPortalMediaFileUrl(token: string, mediaId: string | number): string {
  return `${BASE_URL}${base(token)}/media/${encodeURIComponent(String(mediaId))}/file`;
}
