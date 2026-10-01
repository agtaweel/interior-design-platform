"use client";

/**
 * BRD v4 "Client Marketplace" — mirrors AuthContext.tsx's status machine for the parallel
 * ClientUser identity. No organization-switcher concept at all (a client belongs to no
 * organization) — the whole reason this is a separate context/provider rather than extending
 * AuthContext with an "or maybe it's a client" branch.
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
  fetchClientMe,
  loginClient,
  logoutClient,
  registerClient,
} from "@/lib/api/resources/clientAuth";
import {
  clearStoredClientToken,
  setStoredClientToken,
} from "@/lib/auth/clientTokenStorage";
import type { ClientUserPayload } from "@/lib/api/types";

type ClientAuthStatus = "loading" | "authenticated" | "unauthenticated";

interface ClientAuthContextValue {
  status: ClientAuthStatus;
  client: ClientUserPayload | null;
  login: (email: string, password: string) => Promise<void>;
  register: (input: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    phone?: string;
  }) => Promise<void>;
  logout: () => Promise<void>;
}

const ClientAuthContext = createContext<ClientAuthContextValue | null>(null);

export function ClientAuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<ClientAuthStatus>("loading");
  const [client, setClient] = useState<ClientUserPayload | null>(null);

  useEffect(() => {
    let cancelled = false;

    fetchClientMe()
      .then((me) => {
        if (cancelled) return;
        setClient(me.client);
        setStatus("authenticated");
      })
      .catch(() => {
        if (cancelled) return;
        clearStoredClientToken();
        setStatus("unauthenticated");
      });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- run once on mount only
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const { token, client: loggedInClient } = await loginClient(email, password);
    setStoredClientToken(token);
    setClient(loggedInClient);
    setStatus("authenticated");
  }, []);

  const register = useCallback(
    async (input: {
      name: string;
      email: string;
      password: string;
      password_confirmation: string;
      phone?: string;
    }) => {
      const { token, client: newClient } = await registerClient(input);
      setStoredClientToken(token);
      setClient(newClient);
      setStatus("authenticated");
    },
    [],
  );

  const logout = useCallback(async () => {
    try {
      await logoutClient();
    } catch {
      // Best-effort server-side revocation — proceed with local logout regardless.
    }
    clearStoredClientToken();
    setClient(null);
    setStatus("unauthenticated");
  }, []);

  const value = useMemo(
    () => ({ status, client, login, register, logout }),
    [status, client, login, register, logout],
  );

  return <ClientAuthContext.Provider value={value}>{children}</ClientAuthContext.Provider>;
}

export function useClientAuth(): ClientAuthContextValue {
  const ctx = useContext(ClientAuthContext);
  if (!ctx) throw new Error("useClientAuth must be used within a ClientAuthProvider");
  return ctx;
}
