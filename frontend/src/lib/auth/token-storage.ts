/**
 * Client-side token storage.
 *
 * NOTE (production hardening): storing the bearer token in localStorage is acceptable for
 * this MVP sprint's scope but is not the long-term answer — localStorage is readable by any
 * script on the page (XSS blast radius), unlike an httpOnly cookie. A later hardening pass
 * should move to an httpOnly, Secure, SameSite cookie set by a Next.js route handler (or
 * Laravel Sanctum's SPA cookie mode) so the token never touches JS-accessible storage. Tracked
 * as a follow-up, not blocking Sprint 1.
 */

const TOKEN_KEY = "idp.auth.token";
const ORGANIZATION_KEY = "idp.auth.organizationId";

function isBrowser(): boolean {
  return typeof window !== "undefined";
}

export function getStoredToken(): string | null {
  if (!isBrowser()) return null;
  return window.localStorage.getItem(TOKEN_KEY);
}

export function setStoredToken(token: string): void {
  if (!isBrowser()) return;
  window.localStorage.setItem(TOKEN_KEY, token);
}

export function clearStoredToken(): void {
  if (!isBrowser()) return;
  window.localStorage.removeItem(TOKEN_KEY);
}

export function getStoredOrganizationId(): string | null {
  if (!isBrowser()) return null;
  return window.localStorage.getItem(ORGANIZATION_KEY);
}

export function setStoredOrganizationId(organizationId: string | number): void {
  if (!isBrowser()) return;
  window.localStorage.setItem(ORGANIZATION_KEY, String(organizationId));
}

export function clearStoredOrganizationId(): void {
  if (!isBrowser()) return;
  window.localStorage.removeItem(ORGANIZATION_KEY);
}

export function clearAuthStorage(): void {
  clearStoredToken();
  clearStoredOrganizationId();
}
