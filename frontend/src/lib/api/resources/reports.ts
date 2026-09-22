/**
 * Reports (Sprint 8 — S21). Both JSON endpoints require Permissions::VIEW_FINANCIALS server-side
 * (a 403 ApiError surfaces the same way any other permission-gated read does elsewhere in this
 * app — see ErrorBanner usage in reports/page.tsx). `exportProjectsReport` mirrors `exportBoq`'s
 * pattern in resources/boq.ts: a raw `fetch` (not apiFetch) because the response is a CSV byte
 * stream, not the `{data: ...}` JSON envelope apiFetch parses.
 */

import { apiGetResource, API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type { ReportProjectRow, ReportSummary } from "@/lib/api/types";

export function getReportSummary() {
  return apiGetResource<ReportSummary>("/reports/summary");
}

export function getReportProjects() {
  return apiGetResource<ReportProjectRow[]>("/reports/projects");
}

function authHeaders(): HeadersInit {
  const headers: Record<string, string> = {};
  const token = getStoredToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const organizationId = getStoredOrganizationId();
  if (organizationId) headers["X-Organization-Id"] = organizationId;
  return headers;
}

/** Downloads GET /reports/projects/export and saves it as a file client-side. */
export async function exportProjectsReport(): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/reports/projects/export`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Export failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = "project-financial-report.csv";
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
