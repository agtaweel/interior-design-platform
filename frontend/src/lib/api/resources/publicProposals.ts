/**
 * S11 Client Proposal Portal — the three public, token-authenticated proposal endpoints. Uses
 * `publicClient.ts` (NOT `client.ts`'s `apiFetch`) — see that file's docblock for why the
 * distinction matters. Every call here takes the raw `token` from the URL path; there is no
 * concept of a logged-in user or organization anywhere in this file.
 */

import { publicGetResource, publicPost } from "@/lib/api/publicClient";
import type {
  PublicApproveResult,
  PublicProposalData,
  PublicRequestChangesResult,
} from "@/lib/api/publicTypes";

/** GET /public/proposals/{token} */
export function getPublicProposal(token: string) {
  return publicGetResource<PublicProposalData>(`/public/proposals/${encodeURIComponent(token)}`);
}

/** POST /public/proposals/{token}/approve — body `{name, comment?, otp}`. Returns the flat
 *  success shape directly (NOT wrapped in `{data}` — see PublicProposalController::approve(),
 *  it returns `response()->json($body, 200)` with no envelope). */
export function approvePublicProposal(
  token: string,
  input: { name: string; comment?: string; otp: string },
) {
  return publicPost<PublicApproveResult>(
    `/public/proposals/${encodeURIComponent(token)}/approve`,
    input,
  );
}

/** POST /public/proposals/{token}/request-changes — body `{name, comment}`, no OTP. This one DOES
 *  use the `{data: ...}` envelope (see PublicProposalController::requestChanges()). */
export function requestPublicProposalChanges(token: string, input: { name: string; comment: string }) {
  return publicPost<{ data: PublicRequestChangesResult }>(
    `/public/proposals/${encodeURIComponent(token)}/request-changes`,
    input,
  ).then((res) => res.data);
}

/** GET /public/proposals/{token}/pdf — not fetched via JS; screens should render this as a plain
 *  `<a href>` link so the browser handles the binary download/Content-Disposition natively. */
export function publicProposalPdfUrl(token: string): string {
  return `${
    process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1"
  }/public/proposals/${encodeURIComponent(token)}/pdf`;
}
