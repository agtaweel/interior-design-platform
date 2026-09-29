"use client";

/**
 * App-wide auth state. Token storage caveat: see token-storage.ts (localStorage for MVP,
 * production hardening pass should reconsider).
 *
 * Status machine:
 *  - "loading": checking localStorage / validating the token against GET /me on first mount.
 *  - "authenticated": we have a user + at least one organization membership.
 *  - "unauthenticated": no token, or the token was rejected (401) — caller should redirect to
 *    /login (see app/(protected)/layout.tsx).
 */

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import {
  fetchMe,
  login as loginRequest,
  logout as logoutRequest,
  register as registerRequest,
} from "@/lib/api/resources/auth";
import {
  clearAuthStorage,
  getStoredOrganizationId,
  setStoredOrganizationId,
  setStoredToken,
} from "@/lib/auth/token-storage";
import type { OrganizationMembershipSummary, UserPayload } from "@/lib/api/types";

type AuthStatus = "loading" | "authenticated" | "unauthenticated";

interface AuthContextValue {
  status: AuthStatus;
  user: UserPayload | null;
  organizations: OrganizationMembershipSummary[];
  currentOrganizationId: string | null;
  /**
   * Platform Readiness Review finding #06. Derived from `organizations`, never fetched
   * separately — falls back to "EGP" only when no organization is known yet (e.g. mid-load),
   * matching the money formatter's own pre-existing default so nothing regresses for a user
   * with no organizations at all.
   */
  currentOrganizationCurrency: string;
  login: (email: string, password: string) => Promise<void>;
  /** Platform Readiness Review finding #01 — self-serve organization signup. */
  register: (input: {
    organization_name: string;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => Promise<void>;
  logout: () => Promise<void>;
  setCurrentOrganizationId: (organizationId: string) => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>("loading");
  const [user, setUser] = useState<UserPayload | null>(null);
  const [organizations, setOrganizations] = useState<OrganizationMembershipSummary[]>([]);
  const [currentOrganizationId, setCurrentOrganizationIdState] = useState<string | null>(null);

  const applyMe = useCallback((me: { user: UserPayload; organizations: OrganizationMembershipSummary[] }) => {
    setUser(me.user);
    setOrganizations(me.organizations);

    // If the user belongs to exactly one active organization, the backend infers it
    // automatically (see ResolveTenantContext), but we still pick a sensible default locally
    // so X-Organization-Id is always sent once known — cheap and avoids a class of
    // "which org am I in" bugs if the user later joins a second organization.
    const existing = getStoredOrganizationId();
    const stillValid = existing && me.organizations.some((m) => String(m.organization.id) === existing);

    if (stillValid) {
      setCurrentOrganizationIdState(existing);
    } else if (me.organizations.length > 0) {
      const first = String(me.organizations[0].organization.id);
      setStoredOrganizationId(first);
      setCurrentOrganizationIdState(first);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    fetchMe()
      .then((me) => {
        if (cancelled) return;
        applyMe(me);
        setStatus("authenticated");
      })
      .catch(() => {
        if (cancelled) return;
        // No stored token, an expired/revoked token (401), or a network error all land here —
        // in every case we fall back to "unauthenticated" and let the user sign in again.
        clearAuthStorage();
        setStatus("unauthenticated");
      });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- run once on mount only
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      const { token, user: loggedInUser } = await loginRequest(email, password);
      setStoredToken(token);
      setUser(loggedInUser);

      const me = await fetchMe();
      applyMe(me);
      setStatus("authenticated");
    },
    [applyMe],
  );

  const register = useCallback(
    async (input: {
      organization_name: string;
      name: string;
      email: string;
      password: string;
      password_confirmation: string;
    }) => {
      const { token, user: newUser } = await registerRequest(input);
      setStoredToken(token);
      setUser(newUser);

      const me = await fetchMe();
      applyMe(me);
      setStatus("authenticated");
    },
    [applyMe],
  );

  const logout = useCallback(async () => {
    try {
      await logoutRequest();
    } catch {
      // Best-effort server-side revocation — proceed with local logout regardless.
    }
    clearAuthStorage();
    setUser(null);
    setOrganizations([]);
    setCurrentOrganizationIdState(null);
    setStatus("unauthenticated");
  }, []);

  const setCurrentOrganizationId = useCallback((organizationId: string) => {
    setStoredOrganizationId(organizationId);
    setCurrentOrganizationIdState(organizationId);
  }, []);

  const currentOrganizationCurrency = useMemo(() => {
    const membership = organizations.find(
      (m) => String(m.organization.id) === currentOrganizationId,
    );
    return membership?.organization.currency ?? "EGP";
  }, [organizations, currentOrganizationId]);

  const value = useMemo(
    () => ({
      status,
      user,
      organizations,
      currentOrganizationId,
      currentOrganizationCurrency,
      login,
      register,
      logout,
      setCurrentOrganizationId,
    }),
    [
      status,
      user,
      organizations,
      currentOrganizationId,
      currentOrganizationCurrency,
      login,
      register,
      logout,
      setCurrentOrganizationId,
    ],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within an AuthProvider");
  return ctx;
}
