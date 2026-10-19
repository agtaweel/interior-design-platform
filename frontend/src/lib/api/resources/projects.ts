import { apiGet, apiGetResource, apiPatchResource, apiPost, apiPostResource } from "@/lib/api/client";
import type { Paginated, Project, ProjectFormInput, ProjectStatus } from "@/lib/api/types";

/** GET /projects is a paginated list endpoint — the envelope itself is {data, links, meta}. */
export function listProjects(params: { status?: ProjectStatus; page?: number } = {}) {
  const search = new URLSearchParams();
  if (params.status) search.set("status", params.status);
  if (params.page) search.set("page", String(params.page));
  const qs = search.toString();
  return apiGet<Paginated<Project>>(`/projects${qs ? `?${qs}` : ""}`);
}

export function getProject(id: string | number) {
  return apiGetResource<Project>(`/projects/${id}`);
}

export function createProject(input: ProjectFormInput) {
  return apiPostResource<Project>("/projects", input);
}

export function updateProject(id: string | number, input: Partial<ProjectFormInput>) {
  return apiPatchResource<Project>(`/projects/${id}`, input);
}

/**
 * BRD v3 §17 "Client Portal" — the long-lived link a client bookmarks. Since
 * docs/SIMPLIFICATION_REVIEW.md P3.1 it's the one link they ever get and it can be read again:
 * GET returns the project's current link (or null), POST issues a new one, and revoke without a
 * token closes every portal link of the project. `url` is built by the backend from its
 * configured frontend URL.
 */
export interface ClientPortalLink {
  token: string;
  url: string;
}

export function getClientPortalLink(projectId: string | number) {
  return apiGetResource<ClientPortalLink | null>(`/projects/${projectId}/client-portal-link`);
}

export function issueClientPortalLink(projectId: string | number) {
  return apiPostResource<ClientPortalLink>(`/projects/${projectId}/client-portal-link`, {});
}

export function revokeClientPortalLinks(projectId: string | number) {
  return apiPost<void>(`/projects/${projectId}/client-portal-link/revoke`, {});
}
