/**
 * Organization profile + members (Sprint 8 — Settings, PROJECT_CONTEXT.md S22 scoped down).
 *
 * There is deliberately no `GET /organizations/{id}` endpoint (only PATCH) — the backend only
 * ever needed a way to *write* the branding fields, and `GET /me` only returns `{id, name}` per
 * organization, not the full profile. `getOrganizationProfile` below works around this by
 * PATCHing with an empty body: `UpdateOrganizationRequest`'s rules are all `sometimes`, so an
 * empty payload changes nothing but still returns `new OrganizationResource($organization->fresh())`
 * — verified live against the running backend. This doubles as the settings page's permission
 * check: MANAGE_ORGANIZATION-gated (`UpdateOrganizationRequest::authorize()`), so a 403 here
 * means "hide the organization-profile section for this user" without needing a separate
 * client-side permissions list (the app has none — `GET /me` only returns a role *name*, not
 * its permissions_json).
 */

import { apiGetResource, apiPatchResource, apiPostResource } from "@/lib/api/client";
import type {
  OrganizationMember,
  OrganizationProfile,
  OrganizationProfileFormInput,
} from "@/lib/api/types";

export function getOrganizationProfile(organizationId: string | number) {
  return apiPatchResource<OrganizationProfile>(`/organizations/${organizationId}`, {});
}

export function updateOrganizationProfile(
  organizationId: string | number,
  input: OrganizationProfileFormInput,
) {
  return apiPatchResource<OrganizationProfile>(`/organizations/${organizationId}`, input);
}

/** GET /organizations/{id}/members — not paginated, returns the plain `{data: [...]}` envelope
 *  like every other non-list-paginated endpoint in this API. */
export function listOrganizationMembers(organizationId: string | number) {
  return apiGetResource<OrganizationMember[]>(`/organizations/${organizationId}/members`);
}

export interface InviteMemberInput {
  email: string;
  name?: string;
  role_id: string | number;
}

export function inviteOrganizationMember(organizationId: string | number, input: InviteMemberInput) {
  return apiPostResource<OrganizationMember>(`/organizations/${organizationId}/members/invite`, input);
}

export interface UpdateMemberInput {
  role_id?: string | number;
  status?: "active" | "invited" | "suspended";
}

export function updateOrganizationMember(
  organizationId: string | number,
  memberId: string | number,
  input: UpdateMemberInput,
) {
  return apiPatchResource<OrganizationMember>(
    `/organizations/${organizationId}/members/${memberId}`,
    input,
  );
}
