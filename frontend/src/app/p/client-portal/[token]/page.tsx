"use client";

/**
 * BRD v3 §17 "Client Portal" — Overview page. Renders exactly what
 * GET /public/client-portal/{token} returns via `ClientPortalOverview` (see
 * lib/api/clientPortalTypes.ts) — that type has no cost/margin field at all, matching
 * PublicClientPortalController::overview()'s own leak-prevention boundary.
 */

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { getClientPortalOverview } from "@/lib/api/resources/clientPortal";
import { PublicApiError } from "@/lib/api/publicClient";
import type { ClientPortalOverview } from "@/lib/api/clientPortalTypes";
import { formatEGP } from "@/lib/format/currency";
import { formatDate } from "@/lib/format/date";
import { directionFor } from "@/lib/i18n/textDirection";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { PortalLoading, PortalInvalidToken, PortalLoadError } from "./PortalStates";

export default function ClientPortalOverviewPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;
  const { t, locale } = useLocale();

  const [phase, setPhase] = useState<"loading" | "loaded" | "invalid-token" | "load-error">("loading");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [data, setData] = useState<ClientPortalOverview | null>(null);

  const load = useCallback(() => {
    setPhase("loading");
    getClientPortalOverview(token)
      .then((result) => {
        setData(result);
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

  useEffect(() => {
    if (data) document.title = `${data.project.name} — Client Portal`;
  }, [data]);

  if (phase === "loading") return <PortalLoading message={t("clientPortal.overview.loading")} />;
  if (phase === "invalid-token") return <PortalInvalidToken />;
  if (phase === "load-error") return <PortalLoadError message={errorMessage} onRetry={load} />;
  if (!data) return null;

  const currency = data.organization?.currency ?? "EGP";

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-6 px-4 py-6">
      <header>
        <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
          {data.project.code}
        </p>
        <h1 dir={directionFor(data.project.name)} className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">
          {data.project.name}
        </h1>
        <p className="mt-1 text-sm capitalize text-zinc-500 dark:text-zinc-400">
          {t("clientPortal.overview.statusPrefix")} {data.project.status.replace(/_/g, " ")}
        </p>
        {data.project.start_date || data.project.target_end_date ? (
          <p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-500">
            {data.project.start_date
              ? `${t("clientPortal.overview.startedPrefix")} ${formatDate(data.project.start_date)}`
              : ""}
            {data.project.start_date && data.project.target_end_date ? " · " : ""}
            {data.project.target_end_date
              ? `${t("clientPortal.overview.targetCompletionPrefix")} ${formatDate(data.project.target_end_date)}`
              : ""}
          </p>
        ) : null}
      </header>

      <section className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <FinancialStat label={t("clientPortal.overview.contractValue")} value={data.financials.value} currency={currency} locale={locale} />
        <FinancialStat label={t("clientPortal.overview.collected")} value={data.financials.collected} currency={currency} locale={locale} />
        <FinancialStat label={t("clientPortal.overview.outstanding")} value={data.financials.outstanding} currency={currency} locale={locale} emphasize />
      </section>

      <section className="grid grid-cols-3 gap-3">
        <CountCard label={t("clientPortal.tabs.proposals")} count={data.counts.proposals} />
        <CountCard label={t("clientPortal.tabs.changeOrders")} count={data.counts.change_orders} />
        <CountCard label={t("clientPortal.tabs.payments")} count={data.counts.payments} />
      </section>
    </main>
  );
}

function FinancialStat({
  label,
  value,
  currency,
  locale,
  emphasize,
}: {
  label: string;
  value: number | string;
  currency: string;
  locale: "en" | "ar";
  emphasize?: boolean;
}) {
  return (
    <div
      className={`rounded-lg border p-3 ${
        emphasize
          ? "border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900"
          : "border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
      }`}
    >
      <p className={`text-xs ${emphasize ? "opacity-80" : "text-zinc-500 dark:text-zinc-400"}`}>{label}</p>
      <p className="mt-1 text-sm font-semibold">{formatEGP(value, locale, currency)}</p>
    </div>
  );
}

function CountCard({ label, count }: { label: string; count: number }) {
  return (
    <div className="rounded-lg border border-zinc-200 bg-white p-3 text-center dark:border-zinc-800 dark:bg-zinc-900">
      <p className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{count}</p>
      <p className="text-xs text-zinc-500 dark:text-zinc-400">{label}</p>
    </div>
  );
}
