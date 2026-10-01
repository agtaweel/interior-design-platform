import { apiGetResource, apiPostResource } from "@/lib/api/client";
import type { ChatMessage, InquiryClientHandoff, InquiryDetail, InquirySummary } from "@/lib/api/types";

/**
 * BRD v4 "Client Marketplace" Phase B/C — staff inbox for conversations marketplace clients
 * started with this organization, plus the Phase C hand-off action. Plain apiFetch calls (staff
 * token + X-Organization-Id are injected automatically, unlike the client-side
 * conversations.ts resource).
 */

export function listInquiries() {
  return apiGetResource<InquirySummary[]>("/inquiries");
}

export function getInquiry(conversationId: string | number) {
  return apiGetResource<InquiryDetail>(`/inquiries/${conversationId}`);
}

export function postInquiryMessage(conversationId: string | number, body: string) {
  return apiPostResource<ChatMessage>(`/inquiries/${conversationId}/messages`, { body });
}

export function createClientFromInquiry(conversationId: string | number) {
  return apiPostResource<InquiryClientHandoff>(`/inquiries/${conversationId}/create-client`);
}
