/**
 * BOQ Master Catalog + Standard Templates. Every function takes an optional `platform` flag
 * (default false) that swaps the path prefix from the tenant mount (`/boq-catalog`,
 * `/boq-templates`) to the platform-owner mount (`/platform/boq-catalog`, `/platform/boq-
 * templates`) — mirrors the backend's BoqCatalogController/BoqTemplateAdminController dual-mount
 * design exactly (same controller/service code, different middleware), so one function set
 * serves both the org-level Settings admin screen and the platform-owner admin screen.
 */

import { apiFetch, apiGetResource, apiPatchResource, apiPostResource } from "@/lib/api/client";
import type {
  BoqCatalogItemSummary,
  BoqCatalogTree,
  BoqTemplate,
  BoqTemplateCommitItem,
  BoqTemplateFormInput,
  BoqTemplatePreview,
  BoqTemplatePreviewSelection,
  BoqTemplateUsage,
  BoqTemplateVersion,
  BoqTemplateVersionItemFormInput,
} from "@/lib/api/types";
import type { BoqItem } from "@/lib/api/types";

function base(platform: boolean, segment: "boq-catalog" | "boq-templates"): string {
  return platform ? `/platform/${segment}` : `/${segment}`;
}

// --- Master Catalog ---------------------------------------------------------

export function getBoqCatalogTree(platform = false) {
  return apiGetResource<BoqCatalogTree>(`${base(platform, "boq-catalog")}/categories`);
}

export function searchBoqCatalog(query: string, platform = false) {
  return apiGetResource<BoqCatalogItemSummary[]>(
    `${base(platform, "boq-catalog")}/search?q=${encodeURIComponent(query)}`,
  );
}

export function createBoqCatalogCategory(
  input: { name: string; name_en?: string; name_ar?: string; parent_id?: number | string | null },
  platform = false,
) {
  return apiPostResource(`${base(platform, "boq-catalog")}/categories`, input);
}

export function createBoqCatalogItem(
  categoryId: number | string,
  input: Partial<BoqCatalogItemSummary> & { name: string },
  platform = false,
) {
  return apiPostResource<BoqCatalogItemSummary>(
    `${base(platform, "boq-catalog")}/categories/${categoryId}/items`,
    input,
  );
}

// --- Template headers --------------------------------------------------------

export function listBoqTemplates(platform = false, templateType?: string) {
  const query = templateType ? `?template_type=${encodeURIComponent(templateType)}` : "";
  return apiGetResource<BoqTemplate[]>(`${base(platform, "boq-templates")}${query}`);
}

export function getBoqTemplate(id: number | string, platform = false) {
  return apiGetResource<BoqTemplate>(`${base(platform, "boq-templates")}/${id}`);
}

export function createBoqTemplate(input: BoqTemplateFormInput, platform = false) {
  return apiPostResource<BoqTemplate>(`${base(platform, "boq-templates")}`, input);
}

export function updateBoqTemplate(
  id: number | string,
  input: Partial<BoqTemplateFormInput>,
  platform = false,
) {
  return apiPatchResource<BoqTemplate>(`${base(platform, "boq-templates")}/${id}`, input);
}

export async function deleteBoqTemplate(id: number | string, platform = false): Promise<void> {
  await apiFetch<undefined>(`${base(platform, "boq-templates")}/${id}`, { method: "DELETE" });
}

export function duplicateBoqTemplate(id: number | string, platform = false) {
  return apiPostResource<BoqTemplate>(`${base(platform, "boq-templates")}/${id}/duplicate`);
}

export function activateBoqTemplate(id: number | string, platform = false) {
  return apiPostResource<BoqTemplate>(`${base(platform, "boq-templates")}/${id}/activate`);
}

export function deactivateBoqTemplate(id: number | string, platform = false) {
  return apiPostResource<BoqTemplate>(`${base(platform, "boq-templates")}/${id}/deactivate`);
}

export function getBoqTemplateUsage(id: number | string, platform = false) {
  return apiGetResource<BoqTemplateUsage>(`${base(platform, "boq-templates")}/${id}/usage`);
}

// --- Versions & items ---------------------------------------------------------

export function listBoqTemplateVersions(templateId: number | string, platform = false) {
  return apiGetResource<BoqTemplateVersion[]>(
    `${base(platform, "boq-templates")}/${templateId}/versions`,
  );
}

export function getBoqTemplateVersion(
  templateId: number | string,
  versionId: number | string,
  platform = false,
) {
  return apiGetResource<BoqTemplateVersion>(
    `${base(platform, "boq-templates")}/${templateId}/versions/${versionId}`,
  );
}

export function createBoqTemplateVersion(
  templateId: number | string,
  copyFromVersionId?: number | string,
  platform = false,
) {
  return apiPostResource<BoqTemplateVersion>(
    `${base(platform, "boq-templates")}/${templateId}/versions`,
    copyFromVersionId ? { copy_from_version_id: copyFromVersionId } : undefined,
  );
}

export function publishBoqTemplateVersion(
  templateId: number | string,
  versionId: number | string,
  platform = false,
) {
  return apiPostResource<BoqTemplateVersion>(
    `${base(platform, "boq-templates")}/${templateId}/versions/${versionId}/publish`,
  );
}

export function createBoqTemplateVersionItem(
  templateId: number | string,
  versionId: number | string,
  input: BoqTemplateVersionItemFormInput,
  platform = false,
) {
  return apiPostResource(
    `${base(platform, "boq-templates")}/${templateId}/versions/${versionId}/items`,
    input,
  );
}

export function updateBoqTemplateVersionItem(
  itemId: number | string,
  input: Partial<BoqTemplateVersionItemFormInput>,
  platform = false,
) {
  return apiPatchResource(`${base(platform, "boq-templates")}/items/${itemId}`, input);
}

export async function deleteBoqTemplateVersionItem(
  itemId: number | string,
  platform = false,
): Promise<void> {
  await apiFetch<undefined>(`${base(platform, "boq-templates")}/items/${itemId}`, {
    method: "DELETE",
  });
}

// --- Apply-to-project flow (always the tenant mount — a project only ever belongs to one org) ---

export function previewBoqTemplates(
  projectId: number | string,
  selections: BoqTemplatePreviewSelection[],
) {
  return apiPostResource<BoqTemplatePreview>(`/projects/${projectId}/boq/template-preview`, {
    selections,
  });
}

export function commitBoqTemplates(projectId: number | string, items: BoqTemplateCommitItem[]) {
  return apiPostResource<BoqItem[]>(`/projects/${projectId}/boq/template-commit`, { items });
}
