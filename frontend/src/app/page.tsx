"use client";

/**
 * Front door. Signed-in staff go straight to the dashboard (as before); everyone else sees the
 * public guide instead of being bounced to a bare login form with no idea what the product is.
 * Auth is client-side (token in localStorage — see lib/auth), so this waits for AuthContext to
 * resolve and renders the loading screen meanwhile, which avoids flashing the guide at signed-in
 * users.
 */

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { PublicGuide } from "@/components/guide/PublicGuide";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { useAuth } from "@/lib/auth/AuthContext";
import { useLocale } from "@/lib/i18n/LocaleProvider";

export default function RootPage() {
  const { status } = useAuth();
  const router = useRouter();
  const { t } = useLocale();

  useEffect(() => {
    if (status === "authenticated") router.replace("/dashboard");
  }, [status, router]);

  if (status === "unauthenticated") return <PublicGuide />;
  return <LoadingScreen label={t("common.loading")} />;
}
