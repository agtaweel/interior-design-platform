"use client";

/**
 * S11 — Client Proposal Portal (PRD §4, docs/PROJECT_CONTEXT.md Sprint 4).
 *
 * ## Isolation from the internal app (important — read before touching this route)
 *
 * This route lives at `app/p/proposals/[token]/page.tsx`, OUTSIDE the `(protected)` route
 * group. `(protected)/layout.tsx` is what redirects to `/login` when unauthenticated and wraps
 * children in `<AppShell>` (dashboard nav/chrome) — because this page's URL segment
 * (`/p/proposals/...`) never passes through that route group's layout, neither the redirect nor
 * the internal nav apply here, by construction (Next's file-system routing), not by a runtime
 * check that could be bypassed or accidentally regressed.
 *
 * This page also does NOT import anything from `lib/auth/*` or call `useAuth()` — it talks to
 * the three public endpoints via `lib/api/resources/publicProposals.ts`, which itself is built
 * on `lib/api/publicClient.ts` (a fetch helper deliberately separate from `lib/api/client.ts`'s
 * `apiFetch`, which always attaches `Authorization`/`X-Organization-Id`). The `token` in the URL
 * is the only credential this page ever sends — see those two files' docblocks for the full
 * reasoning.
 *
 * Note: the root layout (`app/layout.tsx`) still wraps every route, including this one, in
 * `LocaleProvider`/`AuthProvider` (there is no separate root layout per route group in this
 * app). `AuthProvider` fires a background `GET /me` on mount regardless of route — harmless here
 * (no token exists for an anonymous visitor, so it 401s silently and this page never reads
 * `useAuth()`), but worth noting: it is not this page reaching for authenticated infrastructure,
 * it is inherited app-wide plumbing that this page simply never consumes.
 *
 * ## Never-leak-cost verification
 *
 * This screen renders exactly what `GET /public/proposals/{token}` returns via
 * `PublicProposalData` (see `lib/api/publicTypes.ts`) — that type has no cost/margin/
 * source_boq_item_id/subtotal/markup/fees/discount fields at all, so there is nothing in this
 * component that *could* render them even by mistake. Verified live against the real backend
 * response (see agent notes) that the JSON payload itself carries none of those fields either.
 */

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { Button } from "@/components/ui/Button";
import {
  getPublicProposal,
  publicProposalPdfUrl,
} from "@/lib/api/resources/publicProposals";
import { PublicApiError } from "@/lib/api/publicClient";
import type { PublicApproveResult, PublicProposalData, PublicProposalStatus } from "@/lib/api/publicTypes";
import { formatDate } from "@/lib/format/date";
import { directionFor } from "@/lib/i18n/textDirection";
import { ApproveForm } from "./ApproveForm";
import { RequestChangesForm } from "./RequestChangesForm";
import {
  ProposalContentSections,
  ProposalGrandTotal,
  ProposalItemsTable,
} from "./ProposalContentSections";

type Phase = "loading" | "loaded" | "invalid-token" | "load-error";
type ActionView = "none" | "approve" | "request-changes";

export default function ClientProposalPortalPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;

  const [phase, setPhase] = useState<Phase>("loading");
  const [loadErrorMessage, setLoadErrorMessage] = useState<string | null>(null);
  const [data, setData] = useState<PublicProposalData | null>(null);
  const [actionView, setActionView] = useState<ActionView>("none");

  // Local overrides so a successful approve/request-changes reflects immediately without
  // requiring a re-fetch, while still matching what a page reload would show (the GET endpoint
  // returns live status/approved_at overlaid on the frozen snapshot — see ProposalPresenter on
  // the backend).
  const [status, setStatus] = useState<PublicProposalStatus | null>(null);
  const [approvedAt, setApprovedAt] = useState<string | null>(null);
  const [justApproved, setJustApproved] = useState(false);
  const [justRequestedChanges, setJustRequestedChanges] = useState(false);

  const load = useCallback(() => {
    setPhase("loading");
    setLoadErrorMessage(null);
    getPublicProposal(token)
      .then((result) => {
        setData(result);
        setStatus(result.proposal.status);
        setApprovedAt(result.proposal.approved_at);
        setPhase("loaded");
      })
      .catch((err: unknown) => {
        if (err instanceof PublicApiError && (err.status === 404 || err.code === "proposal_link_invalid")) {
          setPhase("invalid-token");
          return;
        }
        setLoadErrorMessage(
          err instanceof PublicApiError ? err.message : "Something went wrong loading this proposal.",
        );
        setPhase("load-error");
      });
  }, [token]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (data) {
      document.title = `Proposal — ${data.project.name}`;
    }
  }, [data]);

  function handleApproved(result: PublicApproveResult) {
    setStatus("approved");
    setApprovedAt(result.approved_at);
    setJustApproved(true);
    setActionView("none");
  }

  function handleAlreadyApproved() {
    // Our local `status` was stale (e.g. approved from another device/tab moments earlier).
    // Re-fetch so approved_at and the rest of the payload reflect the server's actual state
    // rather than guessing.
    setActionView("none");
    load();
  }

  function handleRequestedChanges() {
    setStatus("changes_requested");
    setJustRequestedChanges(true);
    setActionView("none");
  }

  if (phase === "loading") {
    return (
      <PortalShell>
        <div className="flex flex-1 items-center justify-center py-24">
          <p className="text-sm text-zinc-500 dark:text-zinc-400">Loading your proposal…</p>
        </div>
      </PortalShell>
    );
  }

  if (phase === "invalid-token") {
    return (
      <PortalShell>
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-24 text-center">
          <div className="text-4xl">🔗</div>
          <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
            This link is no longer valid
          </h1>
          <p className="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
            This proposal link may have expired or been revoked. Please contact your design team
            for an up-to-date link.
          </p>
        </div>
      </PortalShell>
    );
  }

  if (phase === "load-error") {
    return (
      <PortalShell>
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-24 text-center">
          <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
            Couldn&apos;t load this proposal
          </h1>
          <p className="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{loadErrorMessage}</p>
          <Button onClick={load}>Retry</Button>
        </div>
      </PortalShell>
    );
  }

  if (!data || !status) return null;

  const clientName = data.client?.name ?? null;
  const isResolved = status === "approved" || status === "changes_requested";

  return (
    <PortalShell>
      <header className="border-b border-zinc-200 bg-white px-4 py-5 dark:border-zinc-800 dark:bg-zinc-900">
        <div className="mx-auto max-w-2xl">
          <div className="flex items-center gap-3">
            {data.organization?.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element -- external org-hosted logo, no next/image domain config for arbitrary tenant logo hosts.
              <img
                src={data.organization.logo_url}
                alt={data.organization.name}
                className="h-10 w-10 shrink-0 rounded object-contain"
              />
            ) : null}
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {data.organization?.name ?? "Proposal"}
              </p>
              <h1 dir={directionFor(data.project.name)} className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
                {data.project.name}
              </h1>
            </div>
          </div>
          {clientName ? (
            <p dir={directionFor(clientName)} className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
              Prepared for <span className="font-medium">{clientName}</span>
            </p>
          ) : null}
          {data.property ? (
            <p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-500">
              {[data.property.type, data.property.compound, data.property.address]
                .filter(Boolean)
                .join(" · ")}
            </p>
          ) : null}
        </div>
      </header>

      <main className="mx-auto w-full max-w-2xl flex-1 space-y-6 px-4 py-6">
        {isResolved ? (
          <ResolvedBanner
            status={status}
            approvedAt={approvedAt}
            justApproved={justApproved}
            justRequestedChanges={justRequestedChanges}
          />
        ) : null}

        <ProposalContentSections content={data.content} />

        <section>
          <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            Selected Items
          </h2>
          <ProposalItemsTable items={data.items} currency={data.organization?.currency} />
        </section>

        <ProposalGrandTotal grandTotal={data.pricing.grand_total} currency={data.organization?.currency} />

        <div>
          <a
            href={publicProposalPdfUrl(token)}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex w-full items-center justify-center rounded-md border border-zinc-300 px-4 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
          >
            Download PDF
          </a>
        </div>

        {!isResolved ? (
          <section className="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            {actionView === "none" ? (
              <div className="flex flex-col gap-3 sm:flex-row">
                <Button className="flex-1" onClick={() => setActionView("approve")}>
                  Approve Proposal
                </Button>
                <Button
                  variant="secondary"
                  className="flex-1"
                  onClick={() => setActionView("request-changes")}
                >
                  Request Changes
                </Button>
              </div>
            ) : null}

            {actionView === "approve" ? (
              <ApproveForm
                token={token}
                onApproved={handleApproved}
                onAlreadyApproved={handleAlreadyApproved}
                onCancel={() => setActionView("none")}
              />
            ) : null}

            {actionView === "request-changes" ? (
              <RequestChangesForm
                token={token}
                onRequested={handleRequestedChanges}
                onAlreadyApproved={handleAlreadyApproved}
                onCancel={() => setActionView("none")}
              />
            ) : null}
          </section>
        ) : null}
      </main>

      <footer className="px-4 py-6 text-center text-xs text-zinc-400 dark:text-zinc-600">
        Proposal v{data.proposal.version_no}
        {data.proposal.sent_at ? ` · Sent ${formatDate(data.proposal.sent_at)}` : ""}
      </footer>
    </PortalShell>
  );
}

function ResolvedBanner({
  status,
  approvedAt,
  justApproved,
  justRequestedChanges,
}: {
  status: PublicProposalStatus;
  approvedAt: string | null;
  justApproved: boolean;
  justRequestedChanges: boolean;
}) {
  if (status === "approved") {
    return (
      <div className="rounded-lg border border-green-200 bg-green-50 p-4 text-green-900 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
        <p className="text-base font-semibold">
          {justApproved ? "Approved! Thank you." : "This proposal has been approved."}
        </p>
        <p className="mt-1 text-sm">
          {approvedAt ? `Approved on ${formatDate(approvedAt)}. ` : ""}
          Our team will be in touch about next steps.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300">
      <p className="text-base font-semibold">
        {justRequestedChanges ? "We've received your feedback. Thank you!" : "Changes have been requested."}
      </p>
      <p className="mt-1 text-sm">Our team will follow up with a revised proposal.</p>
    </div>
  );
}

function PortalShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-zinc-950">{children}</div>
  );
}
