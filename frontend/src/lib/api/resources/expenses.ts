/**
 * Expenses (BRD "Expenses: project expenses, receipts, supplier linkage"). Mirrors
 * payments.ts's recordPayment/downloadPaymentReceipt pattern for the multipart upload / blob
 * download that don't fit the JSON-in/JSON-out apiFetch shape.
 */

import { apiGetResource, API_BASE_URL } from "@/lib/api/client";
import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";
import type { Expense, ExpenseFormInput } from "@/lib/api/types";

export function getExpenses(projectId: string | number) {
  return apiGetResource<Expense[]>(`/projects/${projectId}/expenses`);
}

function authHeaders(): HeadersInit {
  const headers: Record<string, string> = {};
  const token = getStoredToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  const organizationId = getStoredOrganizationId();
  if (organizationId) headers["X-Organization-Id"] = organizationId;
  return headers;
}

export async function createExpense(projectId: string | number, input: ExpenseFormInput): Promise<Expense> {
  const formData = new FormData();
  if (input.supplier_id) formData.append("supplier_id", String(input.supplier_id));
  formData.append("category", input.category);
  formData.append("description", input.description);
  formData.append("amount", String(input.amount));
  formData.append("expense_date", input.expense_date);
  if (input.notes) formData.append("notes", input.notes);
  if (input.receipt) formData.append("receipt", input.receipt);

  const response = await fetch(`${API_BASE_URL}/projects/${projectId}/expenses`, {
    method: "POST",
    headers: authHeaders(),
    body: formData,
  });

  const json = await response.json();

  if (!response.ok) {
    throw new Error(json?.error?.message ?? `Recording expense failed with status ${response.status}.`);
  }

  return json.data as Expense;
}

export async function downloadExpenseReceipt(expenseId: string | number): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/expenses/${expenseId}/receipt`, {
    headers: authHeaders(),
  });

  if (!response.ok) {
    throw new Error(`Download failed with status ${response.status}.`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `receipt-${expenseId}`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
