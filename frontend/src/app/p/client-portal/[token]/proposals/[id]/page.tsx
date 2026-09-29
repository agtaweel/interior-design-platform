"use client";

/**
 * Reuses the exact same content/items/total renderers as `/p/proposals/[token]` (the
 * per-proposal approval page) — both surfaces render the identical `PublicProposalData` shape
 * (see PublicClientPortalController::proposal(), which wraps the same PublicProposalResource).
 * No approve/request-changes actions here: those still happen exclusively through the
 * proposal's own dedicated approval link/OTP flow (the client received that link separately when
 * the proposal was sent) — this page is a read-only "view it again later" convenience inside the
 * broader portal.
 */

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { getClientPortalProposal, clientPortalProposalPdfUrl } from "@/lib/api/resources/clientPortal";
import { PublicApiError } from "@/lib/api/publicClient";
import type { PublicProposalData } from "@/lib/api/publicTypes";
import { directionFor } from "@/lib/i18n/textDirection";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { PortalLoading, PortalInvalidToken, PortalLoadError } from "../../PortalStates";
import {
  ProposalContentSections,
  ProposalGrandTotal,
  ProposalItemsTable,
} from "@/app/p/proposals/[token]/ProposalContentSections";

export default function ClientPortalProposalDetailPage() {
  const params = useParams<{ token: string; id: string }>();
  const { token, id } = params;
  const { t } = useLocale();

  const [phase, setPhase] = useState<"loading" | "loaded" | "invalid-token" | "load-error">("loading");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [data, setData] = useState<PublicProposalData | null>(null);

  const load = useCallback(() => {
    setPhase("loading");
    getClientPortalProposal(token, id)
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
  }, [token, id, t]);

  useEffect(() => {
    load();
  }, [load]);

  if (phase === "loading") return <PortalLoading message={t("clientPortal.proposalDetail.loading")} />;
  if (phase === "invalid-token") return <PortalInvalidToken />;
  if (phase === "load-error") return <PortalLoadError message={errorMessage} onRetry={load} />;
  if (!data) return null;

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-6 px-4 py-6">
      <header>
        <h1 dir={directionFor(data.project.name)} className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          {t("clientPortal.proposalDetail.title")} v{data.proposal.version_no}
        </h1>
        <p className="mt-0.5 text-xs capitalize text-zinc-500 dark:text-zinc-400">
          {data.proposal.status.replace(/_/g, " ")}
        </p>
      </header>

      <ProposalContentSections content={data.content} />

      <section>
        <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
          {t("clientPortal.proposalDetail.selectedItems")}
        </h2>
        <ProposalItemsTable items={data.items} currency={data.organization?.currency} />
      </section>

      <ProposalGrandTotal grandTotal={data.pricing.grand_total} currency={data.organization?.currency} />

      <a
        href={clientPortalProposalPdfUrl(token, id)}
        target="_blank"
        rel="noopener noreferrer"
        className="inline-flex w-full items-center justify-center rounded-md border border-zinc-300 px-4 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
      >
        {t("clientPortal.proposalDetail.downloadPdf")}
      </a>
    </main>
  );
}
