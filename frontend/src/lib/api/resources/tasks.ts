/**
 * Execution: Tasks (BRD S16 "Kanban/list, assignee, due date, photos"). `createTask` is a
 * bespoke multipart fetch (like recordPayment) since it accepts optional `photos[]` files;
 * `updateTask` is plain JSON since status/field edits never include a file.
 */

import { apiGetResource, apiPatchResource, API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type { ProjectTask, TaskFormInput, TaskStatus } from "@/lib/api/types";

export function getTasks(projectId: string | number) {
  return apiGetResource<ProjectTask[]>(`/projects/${projectId}/tasks`);
}

function authHeaders(): HeadersInit {
  const headers: Record<string, string> = {};
  const token = getStoredToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const organizationId = getStoredOrganizationId();
  if (organizationId) headers["X-Organization-Id"] = organizationId;
  return headers;
}

export async function createTask(projectId: string | number, input: TaskFormInput): Promise<ProjectTask> {
  const formData = new FormData();
  formData.append("title", input.title);
  if (input.description) formData.append("description", input.description);
  if (input.assignee_user_id) formData.append("assignee_user_id", String(input.assignee_user_id));
  if (input.status) formData.append("status", input.status);
  if (input.due_date) formData.append("due_date", input.due_date);
  for (const photo of input.photos ?? []) formData.append("photos[]", photo);

  const response = await fetch(`${API_BASE_URL}/projects/${projectId}/tasks`, {
    method: "POST",
    headers: authHeaders(),
    body: formData,
  });

  const json = await response.json();

  if (!response.ok) {
    throw new Error(json?.error?.message ?? `Creating task failed with status ${response.status}.`);
  }

  return json.data as ProjectTask;
}

export function updateTaskStatus(taskId: string | number, status: TaskStatus) {
  return apiPatchResource<ProjectTask>(`/tasks/${taskId}`, { status });
}
