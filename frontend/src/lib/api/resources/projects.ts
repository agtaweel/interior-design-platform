import { apiGet, apiGetResource, apiPatchResource, apiPostResource } from "@/lib/api/client";
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
