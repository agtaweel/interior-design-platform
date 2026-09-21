"use client";

/**
 * Guards every route nested under this group: redirects to /login unless AuthContext resolved
 * to "authenticated" (see lib/auth/AuthContext.tsx for the loading/authenticated/unauthenticated
 * state machine). This is a client-side guard because the token lives in localStorage, not a
 * cookie the Next.js middleware/server could inspect — acceptable per the token-storage.ts
 * production-hardening note.
 */

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import type { ReactNode } from "react";
import { AppShell } from "@/components/layout/AppShell";
import { useAuth } from "@/lib/auth/AuthContext";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { useLocale } from "@/lib/i18n/LocaleProvider";

export default function ProtectedLayout({ children }: { children: ReactNode }) {
  const { status } = useAuth();
  const router = useRouter();
  const { t } = useLocale();

  useEffect(() => {
    if (status === "unauthenticated") {
      router.replace("/login");
    }
  }, [status, router]);

  if (status !== "authenticated") {
    return <LoadingScreen label={t("common.loading")} />;
  }

  return <AppShell>{children}</AppShell>;
}
