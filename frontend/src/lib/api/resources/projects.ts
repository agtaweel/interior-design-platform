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
 * BRD v3 §17 "Client Portal" — issues the long-lived, multi-use signed link a client can
 * bookmark. See ClientPortalLinkController on the backend; the returned token must be combined
 * client-side with `/p/client-portal/{token}` (the backend has no concept of the frontend's own
 * base URL to build a full link with).
 */
export function issueClientPortalLink(projectId: string | number) {
  return apiPostResource<{ token: string }>(`/projects/${projectId}/client-portal-link`, {});
}

export function revokeClientPortalLink(projectId: string | number, token: string) {
  return apiPost<void>(`/projects/${projectId}/client-portal-link/revoke`, { token });
}
