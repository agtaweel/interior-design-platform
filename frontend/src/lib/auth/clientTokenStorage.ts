/**
 * Marketplace client token storage — deliberately separate keys from token-storage.ts (staff
 * auth). A ClientUser has no organization, so there's no equivalent of ORGANIZATION_KEY here.
 * Same localStorage caveat as the staff token (see that file's docblock) applies equally.
 */

const CLIENT_TOKEN_KEY = "fitout.client.token";

function isBrowser(): boolean {
  return typeof window !== "undefined";
}

export function getStoredClientToken(): string | null {
  if (!isBrowser()) return null;
  return window.localStorage.getItem(CLIENT_TOKEN_KEY);
}

export function setStoredClientToken(token: string): void {
  if (!isBrowser()) return;
  window.localStorage.setItem(CLIENT_TOKEN_KEY, token);
}

export function clearStoredClientToken(): void {
  if (!isBrowser()) return;
  window.localStorage.removeItem(CLIENT_TOKEN_KEY);
}
