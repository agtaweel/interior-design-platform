"use client";

/**
 * BRD v4 "Client Marketplace" Phase C — guards every route under this group (dashboard,
 * projects, deals, messages) the same way (protected)/layout.tsx guards staff routes, against
 * ClientAuthContext instead of AuthContext. Replaces the earlier messages-only layout.tsx now
 * that there's a full client dashboard, not just a messages screen.
 */

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useClientAuth } from "@/lib/auth/ClientAuthContext";
import { ClientAppShell } from "@/components/layout/ClientAppShell";
import { LoadingScreen } from "@/components/ui/LoadingScreen";

export default function ClientAppLayout({ children }: { children: ReactNode }) {
  const { status } = useClientAuth();
  const router = useRouter();

  useEffect(() => {
    if (status === "unauthenticated") {
      router.replace("/client/login");
    }
  }, [status, router]);

  if (status !== "authenticated") {
    return <LoadingScreen label="Loading…" />;
  }

  return <ClientAppShell>{children}</ClientAppShell>;
}
