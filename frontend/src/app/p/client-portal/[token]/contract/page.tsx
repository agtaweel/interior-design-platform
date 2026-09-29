"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { getClientPortalContract } from "@/lib/api/resources/clientPortal";
import { PublicApiError } from "@/lib/api/publicClient";
import type { ClientPortalContract } from "@/lib/api/clientPortalTypes";
import { formatEGP } from "@/lib/format/currency";
import { formatDate } from "@/lib/format/date";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { PortalLoading, PortalInvalidToken, PortalLoadError, PortalEmpty } from "../PortalStates";

export default function ClientPortalContractPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;
  const { t, locale } = useLocale();

  const [phase, setPhase] = useState<"loading" | "loaded" | "none" | "invalid-token" | "load-error">("loading");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [contract, setContract] = useState<ClientPortalContract | null>(null);
  const [currency, setCurrency] = useState("EGP");

  const load = useCallback(() => {
    setPhase("loading");
    getClientPortalContract(token)
      .then((result) => {
        setContract(result.data);
        setCurrency(result.organization?.currency ?? "EGP");
        setPhase("loaded");
      })
      .catch((err: unknown) => {
        if (err instanceof PublicApiError && err.status === 404) {
          // Ambiguous by design (same collapsed-404 posture as every public token surface): an
          // invalid token and "no contract yet" both 404. A working token that later fails here
          // is always "no contract yet" in practice (an invalid token would have already failed
          // on the overview tab), so this reads as the friendlier "none yet" empty state.
          setPhase("none");
          return;
        }
        setErrorMessage(err instanceof PublicApiError ? err.message : t("common.unknownError"));
        setPhase("load-error");
      });
  }, [token, t]);

  useEffect(() => {
    load();
  }, [load]);

  if (phase === "loading") return <PortalLoading message={t("clientPortal.contract.loading")} />;
  if (phase === "invalid-token") return <PortalInvalidToken />;
  if (phase === "load-error") return <PortalLoadError message={errorMessage} onRetry={load} />;
  if (phase === "none" || !contract) return <PortalEmpty message={t("clientPortal.contract.empty")} />;

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-4 px-4 py-6">
      <header>
        <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          {t("clientPortal.contract.title")} {contract.contract_no}
        </h1>
        <p className="mt-0.5 text-xs capitalize text-zinc-500 dark:text-zinc-400">{contract.status}</p>
      </header>

      <div className="rounded-lg bg-zinc-900 px-4 py-4 text-white dark:bg-zinc-100 dark:text-zinc-900">
        <span className="text-sm font-medium uppercase tracking-wide opacity-80">{t("clientPortal.contract.value")}</span>
        <p className="mt-1 text-xl font-semibold">{formatEGP(contract.contract_value, locale, currency)}</p>
      </div>

      <dl className="divide-y divide-zinc-100 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
        <Row label={t("clientPortal.contract.signed")} value={contract.signed_at ? formatDate(contract.signed_at) : "—"} />
        <Row label={t("clientPortal.contract.startDate")} value={contract.start_date ? formatDate(contract.start_date) : "—"} />
        <Row label={t("clientPortal.contract.endDate")} value={contract.end_date ? formatDate(contract.end_date) : "—"} />
      </dl>
    </main>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between px-4 py-3 text-sm">
      <dt className="text-zinc-500 dark:text-zinc-400">{label}</dt>
      <dd className="font-medium text-zinc-900 dark:text-zinc-50">{value}</dd>
    </div>
  );
}
