/**
 * Change Orders (Sprint 7 — S14 Change Orders). Follows the same apiGetResource/
 * apiPostResource/apiPatchResource pattern as proposals.ts/contracts.ts — every endpoint here
 * returns the standard `{"data": ...}` envelope.
 *
 * Immutability rule (see docs/PROJECT_CONTEXT.md Sprint 7, and ChangeOrderController's
 * docblock): a change order is only editable (`updateChangeOrder`) while `status === 'draft'`.
 * Once sent, PATCH returns 409 `CHANGE_ORDER_NOT_EDITABLE`. The frontend's job is to never even
 * show an editable form for a non-draft change order, but the guard exists server-side
 * regardless.
 *
 * Four-verb lifecycle: draft -> sent -> approved|rejected -> applied. `apply` is a separate
 * staff-triggered step from the client's public `approve` — it mutates live BOQ items and the
 * project's contract value, and only succeeds from `approved` (409 CHANGE_ORDER_NOT_APPROVED
 * otherwise, 422 CHANGE_ORDER_NO_CONTRACT if the project has no contract yet).
 */

import { apiGetResource, apiPatchResource, apiPostResource } from "@/lib/api/client";
import type {
  ChangeOrder,
  ChangeOrderCreateInput,
  ChangeOrderSendResult,
  ChangeOrderSummary,
  ChangeOrderUpdateInput,
} from "@/lib/api/types";

/** GET /projects/{id}/change-orders — summaries, newest-created-first (backend orders by
 *  `created_at desc` — verified against ChangeOrderController::index()). */
export function getProjectChangeOrders(projectId: string | number) {
  return apiGetResource<ChangeOrderSummary[]>(`/projects/${projectId}/change-orders`);
}

/** POST /projects/{id}/change-orders — creates a new draft; the server computes each item's
 *  line_delta and the total price_delta from the submitted items. */
export function createChangeOrder(projectId: string | number, input: ChangeOrderCreateInput) {
  return apiPostResource<ChangeOrder>(`/projects/${projectId}/change-orders`, input);
}

/** GET /change-orders/{id} — full internal detail (items, requester, all lifecycle
 *  timestamps). Used by both the editor (draft) and the read-only detail view (sent and
 *  beyond). */
export function getChangeOrder(changeOrderId: string | number) {
  return apiGetResource<ChangeOrder>(`/change-orders/${changeOrderId}`);
}

/** PATCH /change-orders/{id} — updates reason/timeline_delta_days/items. Only succeeds while
 *  status is 'draft'; the caller should only ever invoke this from the editor's draft path (see
 *  file docblock). */
export function updateChangeOrder(changeOrderId: string | number, input: ChangeOrderUpdateInput) {
  return apiPatchResource<ChangeOrder>(`/change-orders/${changeOrderId}`, input);
}

/** POST /change-orders/{id}/send — transitions draft -> sent. The response's `public_url`/
 *  `otp_code` are the ONLY time the OTP is ever returned in plaintext (it's stored hashed) —
 *  the caller must surface them prominently since there is no way to re-fetch the OTP later. */
export function sendChangeOrder(changeOrderId: string | number) {
  return apiPostResource<ChangeOrderSendResult>(`/change-orders/${changeOrderId}/send`);
}

/** POST /change-orders/{id}/apply — only valid from 'approved' (409 CHANGE_ORDER_NOT_APPROVED
 *  otherwise, 422 CHANGE_ORDER_NO_CONTRACT if the project has no signed contract yet). Mutates
 *  live BOQ items and the contract's value, sets status='applied'. */
export function applyChangeOrder(changeOrderId: string | number) {
  return apiPostResource<ChangeOrder>(`/change-orders/${changeOrderId}/apply`);
}
