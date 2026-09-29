/**
 * Procurement & Supplier Intelligence (BRD). Follows the same apiGetResource/apiPostResource/
 * apiPatchResource pattern as clients.ts.
 */

import { apiGetResource, apiPatchResource, apiPostResource } from "@/lib/api/client";
import type { Supplier, SupplierFormInput, SupplierPriceHistoryEntry } from "@/lib/api/types";

export function getSuppliers() {
  return apiGetResource<Supplier[]>("/suppliers");
}

export function createSupplier(input: SupplierFormInput) {
  return apiPostResource<Supplier>("/suppliers", input);
}

export function updateSupplier(supplierId: string | number, input: Partial<SupplierFormInput>) {
  return apiPatchResource<Supplier>(`/suppliers/${supplierId}`, input);
}

export function getSupplierPriceHistory(supplierId: string | number) {
  return apiGetResource<SupplierPriceHistoryEntry[]>(`/suppliers/${supplierId}/price-history`);
}
