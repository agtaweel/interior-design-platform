/**
 * Notifications (Sprint 8 — bell/dropdown UI, PROJECT_CONTEXT.md). GET /notifications returns
 * the current user's own notifications only (auth:sanctum + tenant, no extra permission needed
 * per the backend docblock) — paginated, newest-first. Mark-read endpoints are idempotent.
 */

import { apiGet, apiPost, apiPostResource } from "@/lib/api/client";
import type { Notification, Paginated } from "@/lib/api/types";

export function listNotifications(params: { page?: number } = {}) {
  const search = new URLSearchParams();
  if (params.page) search.set("page", String(params.page));
  const qs = search.toString();
  return apiGet<Paginated<Notification>>(`/notifications${qs ? `?${qs}` : ""}`);
}

export function markNotificationRead(id: string | number) {
  return apiPostResource<Notification>(`/notifications/${id}/read`);
}

/** POST /notifications/read-all has no `{data: ...}` resource to unwrap — just a bare ack. */
export function markAllNotificationsRead() {
  return apiPost<unknown>("/notifications/read-all");
}
