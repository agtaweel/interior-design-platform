/**
 * S14 Public Change Order approval page — the three public, token-authenticated change-order
 * endpoints. Uses `publicClient.ts` (NOT `client.ts`'s `apiFetch`) — see that file's docblock
 * for why the distinction matters; the reasoning is identical for change orders as it is for
 * proposals (`resources/publicProposals.ts`). Every call here takes the raw `token` from the URL
 * path; there is no concept of a logged-in user or organization anywhere in this file.
 */

import { publicGetResource, publicPost } from "@/lib/api/publicClient";
import type {
  PublicChangeOrderApproveResult,
  PublicChangeOrderData,
  PublicChangeOrderRejectResult,
} from "@/lib/api/publicChangeOrderTypes";

/** GET /public/change-orders/{token} */
export function getPublicChangeOrder(token: string) {
  return publicGetResource<PublicChangeOrderData>(
    `/public/change-orders/${encodeURIComponent(token)}`,
  );
}

/** POST /public/change-orders/{token}/approve — body `{name, comment?, otp}`. Returns the flat
 *  success shape directly (NOT wrapped in `{data}` — see
 *  PublicChangeOrderController::approve()). */
export function approvePublicChangeOrder(
  token: string,
  input: { name: string; comment?: string; otp: string },
) {
  return publicPost<PublicChangeOrderApproveResult>(
    `/public/change-orders/${encodeURIComponent(token)}/approve`,
    input,
  );
}

/** POST /public/change-orders/{token}/reject — body `{name, comment}`, no OTP. This one DOES use
 *  the `{data: ...}` envelope (see PublicChangeOrderController::reject()). */
export function rejectPublicChangeOrder(token: string, input: { name: string; comment: string }) {
  return publicPost<{ data: PublicChangeOrderRejectResult }>(
    `/public/change-orders/${encodeURIComponent(token)}/reject`,
    input,
  ).then((res) => res.data);
}
