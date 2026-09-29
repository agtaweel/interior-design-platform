/**
 * Execution: Site Reports (BRD S17 "Work done, issues, decisions, photos, PDF").
 */

import { apiGetResource, API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type { SiteReport, SiteReportFormInput } from "@/lib/api/types";

export function getSiteReports(projectId: string | number) {
  return apiGetResource<SiteReport[]>(`/projects/${projectId}/site-reports`);
}

function authHeaders(): HeadersInit {
  const headers: Record<string, string> = {};
  const token = getStoredToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const organizationId = getStoredOrganizationId();
  if (organizationId) headers["X-Organization-Id"] = organizationId;
  return headers;
}

export async function createSiteReport(
  projectId: string | number,
  input: SiteReportFormInput,
): Promise<SiteReport> {
  const formData = new FormData();
  formData.append("report_date", input.report_date);
  formData.append("work_done", input.work_done);
  if (input.issues) formData.append("issues", input.issues);
  if (input.decisions) formData.append("decisions", input.decisions);
  for (const photo of input.photos ?? []) formData.append("photos[]", photo);

  const response = await fetch(`${API_BASE_URL}/projects/${projectId}/site-reports`, {
    method: "POST",
    headers: authHeaders(),
    body: formData,
  });

  const json = await response.json();

  if (!response.ok) {
    throw new Error(json?.error?.message ?? `Creating site report failed with status ${response.status}.`);
  }

  return json.data as SiteReport;
}

/** Downloads GET /site-reports/{id}/pdf, same raw-fetch-then-blob pattern as
 *  contracts.ts's downloadContractPdf. */
export async function downloadSiteReportPdf(reportId: string | number): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/site-reports/${reportId}/pdf`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Download failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `site-report-${reportId}.pdf`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
