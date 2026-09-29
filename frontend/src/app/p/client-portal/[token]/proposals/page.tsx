"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { getClientPortalProposals } from "@/lib/api/resources/clientPortal";
import { PublicApiError } from "@/lib/api/publicClient";
import type { ClientPortalProposalSummary } from "@/lib/api/clientPortalTypes";
import { formatEGP } from "@/lib/format/currency";
import { formatDate } from "@/lib/format/date";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { PortalLoading, PortalInvalidToken, PortalLoadError, PortalEmpty } from "../PortalStates";

const STATUS_LABEL_KEY: Record<string, TranslationKey> = {
  sent: "clientPortal.proposals.status.sent",
  approved: "clientPortal.proposals.status.approved",
  changes_requested: "clientPortal.proposals.status.changesRequested",
};

export default function ClientPortalProposalsPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;
  const { t, locale } = useLocale();

  const [phase, setPhase] = useState<"loading" | "loaded" | "invalid-token" | "load-error">("loading");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [proposals, setProposals] = useState<ClientPortalProposalSummary[]>([]);
  const [currency, setCurrency] = useState("EGP");

  const load = useCallback(() => {
    setPhase("loading");
    getClientPortalProposals(token)
      .then((result) => {
        setProposals(result.data);
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

  if (phase === "loading") return <PortalLoading message={t("clientPortal.proposals.loading")} />;
  if (phase === "invalid-token") return <PortalInvalidToken />;
  if (phase === "load-error") return <PortalLoadError message={errorMessage} onRetry={load} />;
  if (proposals.length === 0) return <PortalEmpty message={t("clientPortal.proposals.empty")} />;

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-3 px-4 py-6">
      <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.tabs.proposals")}</h1>
      {proposals.map((proposal) => (
        <Link
          key={proposal.id}
          href={`/p/client-portal/${token}/proposals/${proposal.id}`}
          className="block rounded-lg border border-zinc-200 bg-white p-4 hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700"
        >
          <div className="flex items-center justify-between">
            <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
              {t("clientPortal.proposals.versionPrefix")} {proposal.version_no}
            </p>
            <p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{formatEGP(proposal.grand_total, locale, currency)}</p>
          </div>
          <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            {STATUS_LABEL_KEY[proposal.status] ? t(STATUS_LABEL_KEY[proposal.status]) : proposal.status}
            {proposal.sent_at ? ` · ${t("clientPortal.proposals.sentPrefix")} ${formatDate(proposal.sent_at)}` : ""}
          </p>
        </Link>
      ))}
    </main>
  );
}
