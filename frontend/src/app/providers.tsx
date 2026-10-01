"use client";

import type { ReactNode } from "react";
import { AuthProvider } from "@/lib/auth/AuthContext";
import { ClientAuthProvider } from "@/lib/auth/ClientAuthContext";
import { LocaleProvider } from "@/lib/i18n/LocaleProvider";
import { ThemeProvider } from "@/lib/theme/ThemeProvider";

export function Providers({ children }: { children: ReactNode }) {
  return (
    <ThemeProvider>
      <LocaleProvider>
        <AuthProvider>
          <ClientAuthProvider>{children}</ClientAuthProvider>
        </AuthProvider>
      </LocaleProvider>
    </ThemeProvider>
  );
}
