/**
 * Fetch helper for the PUBLIC, token-authenticated proposal endpoints (`/public/proposals/...`,
 * see docs/PROJECT_CONTEXT.md Sprint 4 and backend PublicProposalController).
 *
 * Deliberately NOT `lib/api/client.ts`'s `apiFetch`. That wrapper always attaches
 * `Authorization: Bearer <token>` (from localStorage) and `X-Organization-Id` — both concepts
 * that don't exist on this surface (no login, no Sanctum session, no tenant header; the token in
 * the URL path IS the auth). Reusing it would mean every request from an anonymous client's
 * phone silently carries whatever stale bearer token happens to sit in that browser's
 * localStorage (e.g. a shared/kiosk device previously used by staff) — harmless against these
 * particular endpoints since they ignore auth headers entirely, but importing that module here
 * would blur a boundary this project has been careful about since Sprint 2 ("never leak internal
 * cost/margin data to any client-facing surface"). A small standalone helper keeps that boundary
 * physically visible in the import graph, not just by convention.
 */

export const API_BASE_URL =
  process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";

export interface PublicApiErrorBody {
  code: string;
  message: string;
  details?: Record<string, unknown>;
}

/** Thrown by every helper below for both transport failures and `{error: {...}}` responses. */
export class PublicApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly details: Record<string, unknown>;

  constructor(status: number, body: PublicApiErrorBody) {
    super(body.message);
    this.name = "PublicApiError";
    this.status = status;
    this.code = body.code;
    this.details = body.details ?? {};
  }
}

interface PublicFetchOptions {
  method?: "GET" | "POST";
  body?: unknown;
  headers?: Record<string, string>;
}

async function publicFetch<T>(path: string, options: PublicFetchOptions = {}): Promise<T> {
  const { method = "GET", body, headers } = options;

  const finalHeaders = new Headers(headers);
  finalHeaders.set("Accept", "application/json");
  if (body !== undefined) {
    finalHeaders.set("Content-Type", "application/json");
  }

  let response: Response;
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers: finalHeaders,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new PublicApiError(0, {
      code: "network_error",
      message: "Could not reach the server. Check your connection and try again.",
    });
  }

  const text = await response.text();
  const json = text ? JSON.parse(text) : undefined;

  if (!response.ok) {
    const errorBody: PublicApiErrorBody = json?.error ?? {
      code: "unknown_error",
      message: `Request failed with status ${response.status}.`,
    };
    throw new PublicApiError(response.status, errorBody);
  }

  return json as T;
}

export const publicGet = <T>(path: string) => publicFetch<T>(path, { method: "GET" });

export const publicPost = <T>(path: string, body?: unknown, headers?: Record<string, string>) =>
  publicFetch<T>(path, { method: "POST", body, headers });

/** For endpoints that respond `{"data": T}` (the GET and request-changes endpoints). */
export const publicGetResource = async <T>(path: string): Promise<T> =>
  (await publicGet<{ data: T }>(path)).data;

/** For endpoints that respond `{"data": T, "organization": {"currency": string} | null}` — the
 *  client-portal list endpoints (proposals/contract/payments/change-orders), which need the
 *  org's currency (Platform Readiness Review finding #06) without folding it into `data` itself,
 *  since `data` there is a bare array/resource shared with other call sites. */
export const publicGetResourceWithOrg = <T>(
  path: string,
): Promise<{ data: T; organization: { currency: string } | null }> =>
  publicGet<{ data: T; organization: { currency: string } | null }>(path);
