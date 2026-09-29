/**
 * Platform Readiness Review finding #05 "Audit log viewer". GET /audit-logs is gated behind
 * Permissions::MANAGE_ORGANIZATION server-side (see AuditLogController's docblock) — this
 * frontend has no client-side permissions list either (same constraint documented in
 * settings/page.tsx), so callers should treat a 403 the same way OrganizationProfileSection
 * does: hide the section rather than show an error.
 */

import { apiGet } from "@/lib/api/client";
import type { AuditLogEntry, Paginated } from "@/lib/api/types";

export function listAuditLogs(params: { page?: number; entityType?: string; action?: string } = {}) {
  const search = new URLSearchParams();
  if (params.page) search.set("page", String(params.page));
  if (params.entityType) search.set("entity_type", params.entityType);
  if (params.action) search.set("action", params.action);
  const qs = search.toString();
  return apiGet<Paginated<AuditLogEntry>>(`/audit-logs${qs ? `?${qs}` : ""}`);
}
