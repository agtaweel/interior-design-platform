/**
 * Leads / CRM (BRD "CRM/Leads"). Follows the same apiGetResource/apiPostResource/
 * apiPatchResource pattern as clients.ts/contracts.ts.
 */

import { apiGetResource, apiPatchResource, apiPostResource } from "@/lib/api/client";
import type { Lead, LeadConversionResult, LeadFormInput, LeadStatus } from "@/lib/api/types";

/** GET /leads — newest first. */
export function getLeads() {
  return apiGetResource<Lead[]>("/leads");
}

/** POST /leads. */
export function createLead(input: LeadFormInput) {
  return apiPostResource<Lead>("/leads", input);
}

/** PATCH /leads/{id} — status is restricted to new/contacted/qualified/lost server-side;
 *  moving to "converted" is only ever done via convertLead() below. */
export function updateLeadStatus(leadId: string | number, status: Exclude<LeadStatus, "converted">) {
  return apiPatchResource<Lead>(`/leads/${leadId}`, { status });
}

export function updateLead(leadId: string | number, input: Partial<LeadFormInput>) {
  return apiPatchResource<Lead>(`/leads/${leadId}`, input);
}

/** POST /leads/{id}/convert — always creates a Client; creates a Project too only when
 *  `createProject` is true (in which case `projectName` is required server-side). */
export function convertLead(
  leadId: string | number,
  options?: { createProject?: boolean; projectName?: string },
) {
  return apiPostResource<LeadConversionResult>(`/leads/${leadId}/convert`, {
    create_project: options?.createProject ?? false,
    project_name: options?.projectName,
  });
}
