import { apiGet, apiGetResource, apiPatchResource, apiPostResource } from "@/lib/api/client";
import type { Client, ClientFormInput, Paginated, Property, PropertyFormInput } from "@/lib/api/types";

/** GET /clients is a paginated list endpoint — the envelope itself is {data, links, meta}. */
export function listClients(params: { q?: string; page?: number } = {}) {
  const search = new URLSearchParams();
  if (params.q) search.set("q", params.q);
  if (params.page) search.set("page", String(params.page));
  const qs = search.toString();
  return apiGet<Paginated<Client>>(`/clients${qs ? `?${qs}` : ""}`);
}

export function getClient(id: string | number) {
  return apiGetResource<Client>(`/clients/${id}`);
}

export function createClient(input: ClientFormInput) {
  return apiPostResource<Client>("/clients", input);
}

export function updateClient(id: string | number, input: Partial<ClientFormInput>) {
  return apiPatchResource<Client>(`/clients/${id}`, input);
}

export function createProperty(clientId: string | number, input: PropertyFormInput) {
  return apiPostResource<Property>(`/clients/${clientId}/properties`, input);
}
