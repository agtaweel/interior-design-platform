/**
 * Pricing (Sprint 3 — S08 Pricing Panel). Follows the same apiGetResource/apiPostResource/
 * apiPatchResource pattern as boq.ts/projects.ts. `deletePricingRule` is the one operation that
 * doesn't return a `{data: ...}` body (backend responds 204 No Content, matching a hard-delete
 * convention distinct from BOQ items' soft-archive — see PricingRuleController's docblock for
 * why a hard delete is safe here), so it goes through the raw `apiFetch` and resolves to void.
 */

import { apiFetch, apiGetResource, apiPatchResource, apiPostResource } from "@/lib/api/client";
import type { PricingBreakdown, PricingRule, PricingRuleFormInput } from "@/lib/api/types";

export function getPricingRules(projectId: string | number) {
  return apiGetResource<PricingRule[]>(`/projects/${projectId}/pricing/rules`);
}

export function createPricingRule(projectId: string | number, input: PricingRuleFormInput) {
  return apiPostResource<PricingRule>(`/projects/${projectId}/pricing/rules`, input);
}

export function updatePricingRule(ruleId: string | number, input: Partial<PricingRuleFormInput>) {
  return apiPatchResource<PricingRule>(`/pricing/rules/${ruleId}`, input);
}

export async function deletePricingRule(ruleId: string | number): Promise<void> {
  await apiFetch<undefined>(`/pricing/rules/${ruleId}`, { method: "DELETE" });
}

/** GET .../pricing/breakdown — read-only live preview, never mutates. See PricingBreakdown's
 *  docblock in types.ts for the `priced: false` "never recalculated yet" shape. */
export function getPricingBreakdown(projectId: string | number) {
  return apiGetResource<PricingBreakdown>(`/projects/${projectId}/pricing/breakdown`);
}

/** POST .../pricing/recalculate — persists the cache columns and returns the full fresh
 *  breakdown in one round trip (same shape as getPricingBreakdown's `priced: true` case). */
export function recalculatePricing(projectId: string | number) {
  return apiPostResource<PricingBreakdown>(`/projects/${projectId}/pricing/recalculate`);
}
