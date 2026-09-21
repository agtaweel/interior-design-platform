/**
 * Typed fetch wrapper for the Laravel API (base `/api/v1`, see docs/PROJECT_CONTEXT.md).
 *
 * Responsibilities:
 *  - Prefix every call with the API base URL.
 *  - Attach `Authorization: Bearer <token>` when a token is stored.
 *  - Attach `X-Organization-Id` when a "current organization" is stored (needed for any user
 *    belonging to more than one organization — see backend ResolveTenantContext middleware;
 *    harmless to always send it once we know the id, single-org users just have it ignored/
 *    redundant with the middleware's own single-membership inference).
 *  - Normalize the `{"error":{"code","message","details"}}` envelope into a typed `ApiError`
 *    so every screen can handle failures the same way instead of ad hoc `res.ok` checks.
 */

import { getStoredOrganizationId, getStoredToken } from "@/lib/auth/token-storage";

export const API_BASE_URL =
  process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";

export interface ApiErrorBody {
  code: string;
  message: string;
  details?: Record<string, unknown>;
}

/** Thrown by `apiFetch` for both transport failures and `{error: {...}}` envelope responses. */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly details: Record<string, unknown>;

  constructor(status: number, body: ApiErrorBody) {
    super(body.message);
    this.name = "ApiError";
    this.status = status;
    this.code = body.code;
    this.details = body.details ?? {};
  }
}

export interface ApiFetchOptions extends Omit<RequestInit, "body"> {
  body?: unknown;
  /** Skip attaching the Authorization header (only /auth/login needs this). */
  skipAuth?: boolean;
  /** Skip attaching X-Organization-Id (only /me and /auth/* need this). */
  skipOrganization?: boolean;
}

export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const { body, skipAuth, skipOrganization, headers, ...rest } = options;

  const finalHeaders = new Headers(headers);
  finalHeaders.set("Accept", "application/json");
  if (body !== undefined) {
    finalHeaders.set("Content-Type", "application/json");
  }

  if (!skipAuth) {
    const token = getStoredToken();
    if (token) {
      finalHeaders.set("Authorization", `Bearer ${token}`);
    }
  }

  if (!skipOrganization) {
    const organizationId = getStoredOrganizationId();
    if (organizationId) {
      finalHeaders.set("X-Organization-Id", String(organizationId));
    }
  }

  let response: Response;
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      ...rest,
      headers: finalHeaders,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    // Network failure (backend down, CORS, offline, ...) — no HTTP response at all.
    throw new ApiError(0, {
      code: "network_error",
      message: "Could not reach the server. Check your connection and try again.",
    });
  }

  // 204 No Content and similar bodiless responses.
  const text = await response.text();
  const json = text ? JSON.parse(text) : undefined;

  if (!response.ok) {
    const errorBody: ApiErrorBody = json?.error ?? {
      code: "unknown_error",
      message: `Request failed with status ${response.status}.`,
    };
    throw new ApiError(response.status, errorBody);
  }

  // Return the raw envelope as-is — do NOT unwrap `.data` here. Laravel's envelope shape
  // differs by endpoint kind: single-resource endpoints return `{"data": {...}}` (unwrap to
  // get the resource), but paginated list endpoints return `{"data": [...], "links", "meta"}`
  // where `data`/`links`/`meta` are siblings the caller needs together (see Paginated<T> in
  // types.ts). Unwrapping unconditionally here previously discarded `meta`/`links` for every
  // list endpoint. Callers use `apiGetResource`/`apiPostResource`/`apiPatchResource` below for
  // the single-resource case, or `apiGet` directly (typed as `Paginated<T>`) for lists.
  return json as T;
}

export const apiGet = <T>(path: string, options?: ApiFetchOptions) =>
  apiFetch<T>(path, { ...options, method: "GET" });

export const apiPost = <T>(path: string, body?: unknown, options?: ApiFetchOptions) =>
  apiFetch<T>(path, { ...options, method: "POST", body });

export const apiPatch = <T>(path: string, body?: unknown, options?: ApiFetchOptions) =>
  apiFetch<T>(path, { ...options, method: "PATCH", body });

/** For endpoints that respond `{"data": T}` (every non-paginated endpoint in this API). */
export const apiGetResource = async <T>(path: string, options?: ApiFetchOptions): Promise<T> =>
  (await apiGet<{ data: T }>(path, options)).data;

export const apiPostResource = async <T>(
  path: string,
  body?: unknown,
  options?: ApiFetchOptions,
): Promise<T> => (await apiPost<{ data: T }>(path, body, options)).data;

export const apiPatchResource = async <T>(
  path: string,
  body?: unknown,
  options?: ApiFetchOptions,
): Promise<T> => (await apiPatch<{ data: T }>(path, body, options)).data;
