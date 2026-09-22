/**
 * Payments (Sprint 6 — S13 Payments). Follows the same apiGetResource/apiPostResource pattern as
 * contracts.ts/proposals.ts for the plain JSON endpoints. Two operations don't fit that JSON-in/
 * JSON-out shape and get bespoke fetch calls instead, exactly like boq.ts's exportBoq/importBoq
 * and contracts.ts's downloadContractPdf:
 *  - `recordPayment`: POST is multipart/form-data (optional `receipt` file field) — must NOT set
 *    a JSON Content-Type header (the browser needs to set its own multipart boundary).
 *  - `downloadPaymentReceipt`: GET /payments/{id}/receipt streams the uploaded file (image or
 *    PDF), not a `{data: ...}` envelope — this is an internal/authenticated route (unlike a real
 *    S3 pre-signed URL), so it needs the Authorization/X-Organization-Id headers attached via a
 *    raw `fetch`, then saved client-side as a blob — same pattern as downloadContractPdf.
 *
 * Note: unlike Sprint 4's public proposal-approval flow, this frontend does not send an
 * `Idempotency-Key` header on payment creation — the codebase has never exercised that header
 * from the frontend side even for the other money-moving POST (proposal approval), so recording
 * a payment here follows the same established precedent: the backend supports idempotent replay
 * for a caller that supplies the header (e.g. a future retry-safe client), but this UI doesn't
 * generate one today.
 */

import { apiGetResource, apiPostResource, API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type {
  Payment,
  PaymentFormInput,
  PaymentSchedule,
  PaymentScheduleFormInput,
  ProjectFinancials,
} from "@/lib/api/types";

/** GET /contracts/{id}/payment-schedules — ordered by sequence_no. */
export function getPaymentSchedules(contractId: string | number) {
  return apiGetResource<PaymentSchedule[]>(`/contracts/${contractId}/payment-schedules`);
}

/** POST /contracts/{id}/payment-schedules — creates one installment. Send either `amount`
 *  directly or `percentage` (server computes amount = contract_value * percentage/100 via
 *  bcmath) per the form's percentage/fixed-amount toggle. */
export function createPaymentSchedule(
  contractId: string | number,
  input: PaymentScheduleFormInput,
) {
  return apiPostResource<PaymentSchedule>(`/contracts/${contractId}/payment-schedules`, input);
}

/** GET /payment-schedules/{id}/payments — payment history for one schedule, newest first. */
export function getSchedulePayments(scheduleId: string | number) {
  return apiGetResource<Payment[]>(`/payment-schedules/${scheduleId}/payments`);
}

/** GET /projects/{id}/financials — standalone receivables summary (value/collected/outstanding;
 *  actual_cost/gross_profit stay deferred placeholders per PROJECT_CONTEXT.md Sprint 6 scope
 *  boundary — render those two as "—", never as real numbers). */
export function getProjectFinancials(projectId: string | number) {
  return apiGetResource<ProjectFinancials>(`/projects/${projectId}/financials`);
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
 * Records a payment via POST /payment-schedules/{id}/payments. Uses a raw `fetch` with
 * FormData (not apiFetch) so the browser sets its own `multipart/form-data; boundary=...`
 * Content-Type — see file docblock. Mirrors boq.ts's importBoq: throws a plain `Error` (not
 * `ApiError`) carrying the backend's error message, since this bypasses apiFetch entirely.
 */
export async function recordPayment(
  scheduleId: string | number,
  input: PaymentFormInput,
): Promise<Payment> {
  const formData = new FormData();
  formData.append("amount", String(input.amount));
  formData.append("payment_method", input.payment_method);
  formData.append("paid_at", input.paid_at);
  if (input.reference) formData.append("reference", input.reference);
  if (input.notes) formData.append("notes", input.notes);
  if (input.receipt) formData.append("receipt", input.receipt);

  const response = await fetch(`${API_BASE_URL}/payment-schedules/${scheduleId}/payments`, {
    method: "POST",
    headers: authHeaders(),
    body: formData,
  });

  const json = await response.json();

  if (!response.ok) {
    throw new Error(json?.error?.message ?? `Recording payment failed with status ${response.status}.`);
  }

  return json.data as Payment;
}

const EXTENSION_BY_MIME: Record<string, string> = {
  "application/pdf": "pdf",
  "image/jpeg": "jpg",
  "image/png": "png",
};

/**
 * Downloads GET /payments/{id}/receipt and saves it client-side. Uses a raw `fetch` (not
 * apiFetch) because the response is an image/PDF byte stream, not the JSON envelope apiFetch
 * parses — same pattern as contracts.ts's downloadContractPdf. The file extension is inferred
 * from the response blob's MIME type (the backend infers content-type from the actual file on
 * disk, not a client-trusted header — see PaymentController::receipt()'s docblock).
 */
export async function downloadPaymentReceipt(paymentId: string | number): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/payments/${paymentId}/receipt`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Download failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  const extension = EXTENSION_BY_MIME[blob.type] ?? "bin";
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `receipt-${paymentId}.${extension}`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
