import { apiGetResource } from "@/lib/api/client";
import { getStoredClientToken } from "@/lib/auth/clientTokenStorage";
import type { ClientProjectOverview, ClientProjectSummary, Contract, Payment } from "@/lib/api/types";

/**
 * BRD v4 "Client Marketplace" Phase C — a marketplace client's own projects. Same
 * skipAuth/skipOrganization + manual client-token-header pattern as conversations.ts
 * (apiFetch's built-in Authorization header always reads the STAFF token).
 */

function clientAuthHeader(): HeadersInit {
  const token = getStoredClientToken();
  return token ? { Authorization: `Bearer ${token}` } : {};
}

function clientGet<T>(path: string) {
  return apiGetResource<T>(path, { skipAuth: true, skipOrganization: true, headers: clientAuthHeader() });
}

export function listClientProjects() {
  return clientGet<ClientProjectSummary[]>("/client/projects");
}

export function getClientProjectOverview(projectId: string | number) {
  return clientGet<ClientProjectOverview>(`/client/projects/${projectId}`);
}

export function getClientProjectContract(projectId: string | number) {
  return clientGet<Contract>(`/client/projects/${projectId}/contract`);
}

export function listClientProjectPayments(projectId: string | number) {
  return clientGet<Payment[]>(`/client/projects/${projectId}/payments`);
}

export interface ClientChangeOrderItem {
  description: string;
  quantity: number | string;
  unit: string;
  old_unit_price: number | string;
  new_unit_price: number | string;
  line_delta: number | string;
}

export interface ClientChangeOrder {
  number: string;
  status: string;
  reason: string | null;
  timeline_delta_days: number | null;
  price_delta: number | string | null;
  items: ClientChangeOrderItem[];
  sent_at: string | null;
  approved_at: string | null;
}

export function listClientProjectChangeOrders(projectId: string | number) {
  return clientGet<ClientChangeOrder[]>(`/client/projects/${projectId}/change-orders`);
}
