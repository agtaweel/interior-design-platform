import { apiGetResource, apiPostResource } from "@/lib/api/client";
import { getStoredClientToken } from "@/lib/auth/clientTokenStorage";
import type { ChatMessage, ClientConversationSummary } from "@/lib/api/types";

/**
 * BRD v4 "Client Marketplace" Phase B — a marketplace client's own conversations. Same
 * skipAuth/skipOrganization + manual client-token-header pattern as clientAuth.ts's
 * authenticated calls (apiFetch's built-in Authorization header always reads the STAFF token).
 */

function clientAuthHeader(): HeadersInit {
  const token = getStoredClientToken();
  return token ? { Authorization: `Bearer ${token}` } : {};
}

export function listClientConversations() {
  return apiGetResource<ClientConversationSummary[]>("/client/conversations", {
    skipAuth: true,
    skipOrganization: true,
    headers: clientAuthHeader(),
  });
}

export function startClientConversation(organizationId: string | number) {
  return apiPostResource<ClientConversationSummary>(
    "/client/conversations",
    { organization_id: organizationId },
    { skipAuth: true, skipOrganization: true, headers: clientAuthHeader() },
  );
}

export function listClientConversationMessages(conversationId: string | number) {
  return apiGetResource<ChatMessage[]>(`/client/conversations/${conversationId}/messages`, {
    skipAuth: true,
    skipOrganization: true,
    headers: clientAuthHeader(),
  });
}

export function postClientConversationMessage(conversationId: string | number, body: string) {
  return apiPostResource<ChatMessage>(
    `/client/conversations/${conversationId}/messages`,
    { body },
    { skipAuth: true, skipOrganization: true, headers: clientAuthHeader() },
  );
}
