/**
 * Snagging (BRD S19: defects, priorities, owners, due dates, closure, warranty).
 */

import { apiGetResource, apiPostResource } from "@/lib/api/client";
import type { Snag, SnagFormInput } from "@/lib/api/types";

export function getSnags(projectId: string | number) {
  return apiGetResource<Snag[]>(`/projects/${projectId}/snags`);
}

export function createSnag(projectId: string | number, input: SnagFormInput) {
  return apiPostResource<Snag>(`/projects/${projectId}/snags`, input);
}

export function closeSnag(snagId: string | number, resolutionNotes?: string) {
  return apiPostResource<Snag>(`/snags/${snagId}/close`, { resolution_notes: resolutionNotes });
}

export function reopenSnag(snagId: string | number) {
  return apiPostResource<Snag>(`/snags/${snagId}/reopen`);
}
