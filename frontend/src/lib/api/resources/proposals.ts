/**
 * Proposals (Sprint 4 — S09 Proposal Editor / S10 Version History). Follows the same
 * apiGetResource/apiPostResource/apiPatchResource pattern as boq.ts/pricing.ts — every endpoint
 * here returns the standard `{"data": ...}` envelope, no bespoke fetch calls needed (unlike
 * boq.ts's CSV import/export).
 *
 * Immutability rule (see docs/PROJECT_CONTEXT.md Sprint 4): a proposal_version is only editable
 * (`updateProposal`) while `status === 'draft'`. Once sent, PATCH returns 409
 * `PROPOSAL_NOT_EDITABLE` — verified live. The frontend's job is to never even show an editable
 * form for a non-draft version, but the guard exists server-side regardless.
 */

import { apiGetResource, apiPatchResource, apiPostResource } from "@/lib/api/client";
import type {
  ProposalCreateInput,
  ProposalSendResult,
  ProposalUpdateInput,
  ProposalVersion,
  ProposalVersionSummary,
} from "@/lib/api/types";

/** GET /projects/{id}/proposals — version summaries for S10, newest version first (verified
 *  live: the backend returns them in descending version_no order). */
export function getProjectProposals(projectId: string | number) {
  return apiGetResource<ProposalVersionSummary[]>(`/projects/${projectId}/proposals`);
}

/** POST /projects/{id}/proposals — creates a new draft version (version_no auto-increments per
 *  project), snapshotting the project's *current* BOQ/pricing into `items`/totals. Note (verified
 *  live): the backend does not block creating a second draft while one already exists — this
 *  frontend's own UI flow is what keeps "one draft at a time" true in practice, by only offering
 *  "Create New Version" when the latest version isn't a draft. */
export function createProposal(projectId: string | number, input: ProposalCreateInput = {}) {
  return apiPostResource<ProposalVersion>(`/projects/${projectId}/proposals`, input);
}

/** GET /proposals/{id} — full internal detail (content, items, totals). Used by both the editor
 *  (draft) and the read-only detail view (sent/approved/changes_requested). */
export function getProposal(proposalId: string | number) {
  return apiGetResource<ProposalVersion>(`/proposals/${proposalId}`);
}

/** PATCH /proposals/{id} — updates content_json. Only succeeds while status is 'draft'; the
 *  caller should only ever invoke this from the editor's draft path (see file docblock). */
export function updateProposal(proposalId: string | number, input: ProposalUpdateInput) {
  return apiPatchResource<ProposalVersion>(`/proposals/${proposalId}`, input);
}

/** POST /proposals/{id}/send — transitions draft -> sent. The response's `public_url`/`otp_code`
 *  are the ONLY time the OTP is ever returned in plaintext (it's stored hashed) — the caller must
 *  surface them prominently since there is no way to re-fetch the OTP later (see GET /proposals/
 *  {id}'s docblock in types.ts: it has no otp field at all). */
export function sendProposal(proposalId: string | number) {
  return apiPostResource<ProposalSendResult>(`/proposals/${proposalId}/send`);
}
