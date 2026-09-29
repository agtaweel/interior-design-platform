"use client";

/**
 * Platform Readiness Review finding #06. Binds formatEGP/formatEGPOrDash to the current locale
 * AND the current organization's real currency (useAuth().currentOrganizationCurrency) in one
 * place, so call sites across the authenticated app don't each need to import useAuth() and
 * thread `currentOrganizationCurrency` through individually. Only for the authenticated
 * (protected) app — public/token-authenticated pages have no AuthContext and should instead read
 * currency straight off whatever public payload they already receive (e.g. a proposal's own
 * `organization.currency`), calling formatEGP/formatEGPOrDash directly.
 */

import { useMemo } from "react";
import { useAuth } from "@/lib/auth/AuthContext";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatEGP, formatEGPOrDash } from "@/lib/format/currency";

export function useMoneyFormatter() {
  const { locale } = useLocale();
  const { currentOrganizationCurrency } = useAuth();

  return useMemo(
    () => ({
      formatMoney: (amount: number | string | null | undefined) =>
        formatEGP(amount, locale, currentOrganizationCurrency),
      formatMoneyOrDash: (amount: number | string | null | undefined) =>
        formatEGPOrDash(amount, locale, currentOrganizationCurrency),
    }),
    [locale, currentOrganizationCurrency],
  );
}
