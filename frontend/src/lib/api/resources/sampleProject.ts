/**
 * docs/SIMPLIFICATION_REVIEW.md P4.3 — the company's one-click sample project
 * (backend: SampleProjectController / SampleProjectService). At most one per company.
 */

import { apiFetch, apiGetResource, apiPostResource } from "@/lib/api/client";
import type { Locale } from "@/lib/i18n/dictionaries";

export interface SampleProjectRef {
  project_id: number | string;
}

export function getSampleProject() {
  return apiGetResource<SampleProjectRef | null>("/sample-project");
}

/** Created in the user's current language; returns the existing one if there already is one. */
export function createSampleProject(locale: Locale) {
  return apiPostResource<SampleProjectRef>("/sample-project", { locale });
}

export async function deleteSampleProject() {
  await apiFetch<undefined>("/sample-project", { method: "DELETE" });
}
