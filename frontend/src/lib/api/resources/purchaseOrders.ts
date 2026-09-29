/**
 * Purchase Order lifecycle (BRD "Procurement"): draft -> sent -> partially_received ->
 * received -> cancelled.
 */

import { apiGetResource, apiPostResource } from "@/lib/api/client";
import type { PurchaseOrder, PurchaseOrderFormInput, ReceivePurchaseOrderLine } from "@/lib/api/types";

export function getPurchaseOrders(projectId: string | number) {
  return apiGetResource<PurchaseOrder[]>(`/projects/${projectId}/purchase-orders`);
}

export function createPurchaseOrder(projectId: string | number, input: PurchaseOrderFormInput) {
  return apiPostResource<PurchaseOrder>(`/projects/${projectId}/purchase-orders`, input);
}

export function sendPurchaseOrder(poId: string | number) {
  return apiPostResource<PurchaseOrder>(`/purchase-orders/${poId}/send`);
}

export function receivePurchaseOrder(poId: string | number, lines: ReceivePurchaseOrderLine[]) {
  return apiPostResource<PurchaseOrder>(`/purchase-orders/${poId}/receive`, { items: lines });
}

export function cancelPurchaseOrder(poId: string | number) {
  return apiPostResource<PurchaseOrder>(`/purchase-orders/${poId}/cancel`);
}
