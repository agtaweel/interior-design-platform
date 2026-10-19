"use client";

/**
 * S11 — per-document proposal approval page (PRD §4, docs/PROJECT_CONTEXT.md Sprint 4).
 *
 * Since docs/SIMPLIFICATION_REVIEW.md P3 the client portal is the one link staff send, and the
 * client decides inside it; this page stays for links already sent before that change. It is now
 * bilingual (P3.2) and uses the same decision panel as the portal (components/client-decision),
 * whose endpoint applies the same rules (ProposalClientDecision on the backend).
 *
 * ## Isolation from the internal app (important — read before touching this route)
 *
 * This route lives OUTSIDE the `(protected)` route group, so neither its login redirect nor the
 * internal nav apply here — by construction (Next's file-system routing), not by a runtime check.
 * It does not import `lib/auth/*` or call `useAuth()`; it talks only to the public endpoints via
 * `lib/api/resources/publicProposals.ts` (built on `publicClient.ts`, which never attaches
 * Authorization/X-Organization-Id). The URL token is the only credential it sends.
 *
 * ## Never-leak-cost verification
 *
 * Renders exactly what `GET /public/proposals/{token}` returns via `PublicProposalData` — that
 * type has no cost/margin/subtotal/markup fields at all, so nothing here could render them.
 */

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { Button } from "@/components/ui/Button";
import { LanguageToggle } from "@/components/ui/LanguageToggle";
import { DecisionBanner, DecisionPanel, type DecisionOutcome } from "@/components/client-decision/DecisionPanel";
import {
  approvePublicProposal,
  getPublicProposal,
  publicProposalPdfUrl,
  requestPublicProposalChanges,
} from "@/lib/api/resources/publicProposals";
import { PublicApiError } from "@/lib/api/publicClient";
import type { PublicProposalData } from "@/lib/api/publicTypes";
import { formatDate } from "@/lib/format/date";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { directionFor } from "@/lib/i18n/textDirection";
import { ProposalContentSections, ProposalGrandTotal, ProposalItemsTable } from "./ProposalContentSections";

type Phase = "loading" | "loaded" | "invalid-token" | "load-error";

export default function ClientProposalPortalPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;
  const { t, locale } = useLocale();

  const [phase, setPhase] = useState<Phase>("loading");
  const [loadErrorMessage, setLoadErrorMessage] = useState<string | null>(null);
  const [data, setData] = useState<PublicProposalData | null>(null);
  const [justDecided, setJustDecided] = useState(false);

  const load = useCallback(() => {
    setPhase("loading");
    setLoadErrorMessage(null);
    getPublicProposal(token)
      .then((result) => {
        setData(result);
        setPhase("loaded");
      })
      .catch((err: unknown) => {
        if (err instanceof PublicApiError && (err.status === 404 || err.code === "proposal_link_invalid")) {
          setPhase("invalid-token");
          return;
        }
        setLoadErrorMessage(err instanceof PublicApiError ? err.message : null);
        setPhase("load-error");
      });
  }, [token]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (data) document.title = `${t("clientPortal.proposalDetail.title")} — ${data.project.name}`;
  }, [data, t]);

  function handleDecided(outcome: DecisionOutcome) {
    if (outcome === "alreadyDecided") {
      load();
      return;
    }
    setJustDecided(true);
    setData((prev) =>
      prev ? { ...prev, proposal: { ...prev.proposal, status: outcome === "approved" ? "approved" : "changes_requested" } } : prev,
    );
  }

  if (phase === "loading") {
    return (
      <PortalShell>
        <div className="flex flex-1 items-center justify-center py-24">
          <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("clientPortal.proposalDetail.loading")}</p>
        </div>
      </PortalShell>
    );
  }

  if (phase === "invalid-token") {
    return (
      <PortalShell>
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-24 text-center">
          <div className="text-4xl" aria-hidden="true">🔗</div>
          <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.invalidToken.title")}</h1>
          <p className="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{t("publicDoc.invalidBody")}</p>
        </div>
      </PortalShell>
    );
  }

  if (phase === "load-error") {
    return (
      <PortalShell>
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-24 text-center">
          <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("clientPortal.loadError.title")}</h1>
          {loadErrorMessage ? <p className="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{loadErrorMessage}</p> : null}
          <Button onClick={load}>{t("common.retry")}</Button>
        </div>
      </PortalShell>
    );
  }

  if (!data) return null;

  const status = data.proposal.status;
  const clientName = data.client?.name ?? null;

  return (
    <PortalShell>
      <header className="border-b border-zinc-200 bg-white px-4 py-5 sm:px-6 lg:px-8 dark:border-zinc-800 dark:bg-zinc-900">
        <div className="w-full">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-center gap-3">
              {data.organization?.logo_url ? (
                // eslint-disable-next-line @next/next/no-img-element -- external org-hosted logo, no next/image domain config for arbitrary tenant logo hosts.
                <img src={data.organization.logo_url} alt={data.organization.name} className="h-10 w-10 shrink-0 rounded object-contain" />
              ) : null}
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                  {data.organization?.name ?? t("clientPortal.proposalDetail.title")}
                </p>
                <h1 dir={directionFor(data.project.name)} className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
                  {data.project.name}
                </h1>
              </div>
            </div>
            <LanguageToggle />
          </div>
          {clientName ? (
            <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
              {t("publicDoc.preparedFor")} <span dir={directionFor(clientName)} className="font-medium">{clientName}</span>
            </p>
          ) : null}
          {data.property ? (
            <p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-500">
              {[data.property.type ? t(`property.type.${data.property.type}` as TranslationKey) : null, data.property.compound, data.property.address]
                .filter(Boolean)
                .join(" · ")}
            </p>
          ) : null}
        </div>
      </header>

      <main className="w-full flex-1 space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <DecisionBanner kind="proposal" status={status} justNow={justDecided} />

        <ProposalContentSections content={data.content} />

        <section>
          <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            {t("clientPortal.proposalDetail.selectedItems")}
          </h2>
          <ProposalItemsTable items={data.items} currency={data.organization?.currency} />
        </section>

        <ProposalGrandTotal grandTotal={data.pricing.grand_total} currency={data.organization?.currency} />

        <a
          href={publicProposalPdfUrl(token)}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex w-full items-center justify-center rounded-md border border-zinc-300 px-4 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
        >
          {t("clientPortal.proposalDetail.downloadPdf")}
        </a>

        {status === "sent" ? (
          <DecisionPanel
            kind="proposal"
            approve={(input) => approvePublicProposal(token, input)}
            decline={(input) => requestPublicProposalChanges(token, input)}
            onDecided={handleDecided}
          />
        ) : null}
      </main>

      <footer className="px-4 py-6 text-center text-xs text-zinc-400 dark:text-zinc-600">
        {t("clientPortal.proposalDetail.title")} v{data.proposal.version_no}
        {data.proposal.sent_at ? ` · ${t("clientPortal.proposals.sentPrefix")} ${formatDate(data.proposal.sent_at, locale)}` : ""}
      </footer>
    </PortalShell>
  );
}

function PortalShell({ children }: { children: React.ReactNode }) {
  return <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-zinc-950">{children}</div>;
}
