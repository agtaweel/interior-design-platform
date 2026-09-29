"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { getClientPortalPayments } from "@/lib/api/resources/clientPortal";
import { PublicApiError } from "@/lib/api/publicClient";
import type { ClientPortalPayment } from "@/lib/api/clientPortalTypes";
import { formatEGP } from "@/lib/format/currency";
import { formatDate } from "@/lib/format/date";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { PortalLoading, PortalInvalidToken, PortalLoadError, PortalEmpty } from "../PortalStates";

export default function ClientPortalPaymentsPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;
  const { t, locale } = useLocale();

  const [phase, setPhase] = useState<"loading" | "loaded" | "invalid-token" | "load-error">("loading");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [payments, setPayments] = useState<ClientPortalPayment[]>([]);
  const [currency, setCurrency] = useState("EGP");

  const load = useCallback(() => {
    setPhase("loading");
    getClientPortalPayments(token)
      .then((result) => {
        setPayments(result.data);
        setCurrency(result.organization?.currency ?? "EGP");
        setPhase("loaded");
      })
      .catch((err: unknown) => {
        if (err instanceof PublicApiError && err.status === 404) {
          setPhase("invalid-token");
          return;
        }
        setErrorMessage(err instanceof PublicApiError ? err.message : t("common.unknownError"));
        setPhase("load-error");
      });
  }, [token, t]);

  useEffect(() => {
    load();
  }, [load]);

  if (phase === "loading") return <PortalLoading message={t("clientPortal.payments.loading")} />;
  if (phase === "invalid-token") return <PortalInvalidToken />;
  if (phase === "load-error") return <PortalLoadError message={errorMessage} onRetry={load} />;
  if (payments.length === 0) return <PortalEmpty message={t("clientPortal.payments.empty")} />;

  const total = payments.reduce((sum, p) => sum + Number(p.amount), 0);

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-4 px-4 py-6">
      <header className="flex items-center justify-between">
        <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.tabs.payments")}</h1>
        <p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{formatEGP(total, locale, currency)}</p>
      </header>

      <div className="divide-y divide-zinc-100 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
        {payments.map((payment) => (
          <div key={payment.id} className="flex items-center justify-between px-4 py-3">
            <div>
              <p className="text-sm font-medium capitalize text-zinc-900 dark:text-zinc-50">
                {payment.payment_method.replace(/_/g, " ")}
              </p>
              <p className="text-xs text-zinc-500 dark:text-zinc-400">
                {formatDate(payment.paid_at)}
                {payment.reference ? ` · ${t("clientPortal.payments.refPrefix")} ${payment.reference}` : ""}
              </p>
            </div>
            <p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{formatEGP(payment.amount, locale, currency)}</p>
          </div>
        ))}
      </div>
    </main>
  );
}
