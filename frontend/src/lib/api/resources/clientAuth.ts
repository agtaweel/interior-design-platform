import { apiGetResource, apiPostResource } from "@/lib/api/client";
import { getStoredClientToken } from "@/lib/auth/clientTokenStorage";
import type { ClientLoginResponseData, ClientMeResponseData } from "@/lib/api/types";

/**
 * Mirrors lib/api/resources/auth.ts for the parallel /client/auth/* + /client/me endpoints.
 * `apiFetch`'s built-in Authorization injection always reads the STAFF token (token-storage.ts),
 * so authenticated client calls (logout/me) pass `skipAuth: true` and attach the client token
 * manually here instead — register/login/forgot/reset need no token at all, same as their staff
 * equivalents.
 */

function clientAuthHeader(): HeadersInit {
  const token = getStoredClientToken();
  return token ? { Authorization: `Bearer ${token}` } : {};
}

export function registerClient(input: {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  phone?: string;
}) {
  return apiPostResource<ClientLoginResponseData>("/client/auth/register", input, {
    skipAuth: true,
    skipOrganization: true,
  });
}

export function loginClient(email: string, password: string) {
  return apiPostResource<ClientLoginResponseData>(
    "/client/auth/login",
    { email, password },
    { skipAuth: true, skipOrganization: true },
  );
}

export function logoutClient() {
  return apiPostResource<{ message: string }>("/client/auth/logout", undefined, {
    skipAuth: true,
    skipOrganization: true,
    headers: clientAuthHeader(),
  });
}

export function fetchClientMe() {
  return apiGetResource<ClientMeResponseData>("/client/me", {
    skipAuth: true,
    skipOrganization: true,
    headers: clientAuthHeader(),
  });
}

export function forgotClientPassword(email: string) {
  return apiPostResource<{ message: string }>(
    "/client/auth/forgot-password",
    { email },
    { skipAuth: true, skipOrganization: true },
  );
}

export function resetClientPassword(input: {
  email: string;
  token: string;
  password: string;
  password_confirmation: string;
}) {
  return apiPostResource<{ message: string }>("/client/auth/reset-password", input, {
    skipAuth: true,
    skipOrganization: true,
  });
}
