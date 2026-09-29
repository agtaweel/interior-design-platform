import { apiGetResource, apiPostResource } from "@/lib/api/client";
import type { LoginResponseData, MeResponseData } from "@/lib/api/types";

export function login(email: string, password: string) {
  return apiPostResource<LoginResponseData>(
    "/auth/login",
    { email, password },
    { skipAuth: true, skipOrganization: true },
  );
}

/**
 * Platform Readiness Review finding #01 — self-serve organization signup. Response shape is
 * identical to login()'s (`{token, user}`), since a brand-new signup logs the owner straight in.
 */
export function register(input: {
  organization_name: string;
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}) {
  return apiPostResource<LoginResponseData>("/auth/register", input, {
    skipAuth: true,
    skipOrganization: true,
  });
}

export function logout() {
  return apiPostResource<{ message: string }>("/auth/logout", undefined, { skipOrganization: true });
}

/** GET /me is intentionally cross-organization, so it never needs X-Organization-Id. */
export function fetchMe() {
  return apiGetResource<MeResponseData>("/me", { skipOrganization: true });
}

/**
 * Platform Readiness Review finding #04. Always resolves with the same generic message
 * regardless of whether the email belongs to a real account — the backend deliberately never
 * reveals that, so this screen must never branch its UI on the response content either.
 */
export function forgotPassword(email: string) {
  return apiPostResource<{ message: string }>(
    "/auth/forgot-password",
    { email },
    { skipAuth: true, skipOrganization: true },
  );
}

export function resetPassword(input: {
  email: string;
  token: string;
  password: string;
  password_confirmation: string;
}) {
  return apiPostResource<{ message: string }>("/auth/reset-password", input, {
    skipAuth: true,
    skipOrganization: true,
  });
}
