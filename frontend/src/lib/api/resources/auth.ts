import { apiGetResource, apiPostResource } from "@/lib/api/client";
import type { LoginResponseData, MeResponseData } from "@/lib/api/types";

export function login(email: string, password: string) {
  return apiPostResource<LoginResponseData>(
    "/auth/login",
    { email, password },
    { skipAuth: true, skipOrganization: true },
  );
}

export function logout() {
  return apiPostResource<{ message: string }>("/auth/logout", undefined, { skipOrganization: true });
}

/** GET /me is intentionally cross-organization, so it never needs X-Organization-Id. */
export function fetchMe() {
  return apiGetResource<MeResponseData>("/me", { skipOrganization: true });
}
