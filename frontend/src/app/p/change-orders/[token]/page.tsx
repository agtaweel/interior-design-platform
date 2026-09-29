"use client";

/**
 * S14 — Public Change Order approval page (PRD Sprint 7, docs/PROJECT_CONTEXT.md "Sprint 7
 * scope"). Sibling to `/p/proposals/[token]/page.tsx` — built to the identical isolation
 * architecture and mobile-first pattern; read that file's docblock first if you haven't, the
 * reasoning below is a condensed adaptation of it, not a replacement.
 *
 * ## Isolation from the internal app (same as the proposal portal — read before touching this route)
 *
 * This route lives at `app/p/change-orders/[token]/page.tsx`, OUTSIDE the `(protected)` route
 * group, so `(protected)/layout.tsx`'s auth redirect and `<AppShell>` chrome never apply here —
 * by construction (Next's file-system routing), not a runtime check that could regress.
 *
 * This page does NOT import anything from `lib/auth/*`, does not call `useAuth()`, and does NOT
 * use `lib/api/client.ts`'s `apiFetch`. It talks to the three public endpoints via
 * `lib/api/resources/publicChangeOrders.ts`, which is built on the same `lib/api/publicClient.ts`
 * fetch helper the proposal portal uses (deliberately generic, not proposal-specific — see that
 * file's docblock). The `token` in the URL is the only credential this page ever sends.
 *
 * (The root layout's `LocaleProvider`/`AuthProvider` still wrap this route like every other one
 * in the app — inherited plumbing this page never consumes, same as the proposal portal.)
 *
 * ## Never-leak-internal-linkage verification
 *
 * This screen renders exactly what `GET /public/change-orders/{token}` returns via
 * `PublicChangeOrderData` (see `lib/api/publicChangeOrderTypes.ts`) — no `id`, no `boq_item_id`,
 * no `project_id`/organization/client header info at all (verified live against the real
 * backend response — this endpoint's payload is even more minimal than the proposal portal's,
 * it has no header/org/client/property fields whatsoever). `price_delta` and each item's
 * `old_unit_price`/`new_unit_price`/`line_delta` ARE shown, deliberately — a price delta is the
 * commercial thing being approved here, unlike BOQ internal cost/margin data.
 */

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import {
  getPublicChangeOrder,
} from "@/lib/api/resources/publicChangeOrders";
import { PublicApiError } from "@/lib/api/publicClient";
import type {
  PublicChangeOrderApproveResult,
  PublicChangeOrderData,
  PublicChangeOrderStatus,
} from "@/lib/api/publicChangeOrderTypes";
import { formatDate } from "@/lib/format/date";
import { ApproveForm } from "./ApproveForm";
import { RejectForm } from "./RejectForm";
import {
  ChangeOrderItemsTable,
  ChangeOrderPriceImpact,
  ChangeOrderReason,
  ChangeOrderTimelineImpact,
} from "./ChangeOrderContentSections";

type Phase = "loading" | "loaded" | "invalid-token" | "load-error";
type ActionView = "none" | "approve" | "reject";

const STATUS_TONE: Record<PublicChangeOrderStatus, "neutral" | "blue" | "green" | "amber" | "red"> = {
  draft: "neutral",
  sent: "blue",
  approved: "green",
  rejected: "red",
  applied: "amber",
};

export default function ClientChangeOrderPortalPage() {
  const params = useParams<{ token: string }>();
  const token = params.token;

  const [phase, setPhase] = useState<Phase>("loading");
  const [loadErrorMessage, setLoadErrorMessage] = useState<string | null>(null);
  const [data, setData] = useState<PublicChangeOrderData | null>(null);
  const [actionView, setActionView] = useState<ActionView>("none");

  // Local overrides so a successful approve/reject reflects immediately without requiring a
  // re-fetch, while still matching what a page reload would show (the GET endpoint returns the
  // live status/approved_at). See the proposal portal's page.tsx for the identical pattern.
  const [status, setStatus] = useState<PublicChangeOrderStatus | null>(null);
  const [approvedAt, setApprovedAt] = useState<string | null>(null);
  const [justApproved, setJustApproved] = useState(false);
  const [justRejected, setJustRejected] = useState(false);

  const load = useCallback(() => {
    setPhase("loading");
    setLoadErrorMessage(null);
    getPublicChangeOrder(token)
      .then((result) => {
        setData(result);
        setStatus(result.status);
        setApprovedAt(result.approved_at);
        setPhase("loaded");
      })
      .catch((err: unknown) => {
        if (err instanceof PublicApiError && (err.status === 404 || err.code === "change_order_link_invalid")) {
          setPhase("invalid-token");
          return;
        }
        setLoadErrorMessage(
          err instanceof PublicApiError ? err.message : "Something went wrong loading this change order.",
        );
        setPhase("load-error");
      });
  }, [token]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (data) {
      document.title = `Change Order ${data.number}`;
    }
  }, [data]);

  function handleApproved(result: PublicChangeOrderApproveResult) {
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

  function handleRejected() {
    setStatus("rejected");
    setJustRejected(true);
    setActionView("none");
  }

  if (phase === "loading") {
    return (
      <PortalShell>
        <div className="flex flex-1 items-center justify-center py-24">
          <p className="text-sm text-zinc-500 dark:text-zinc-400">Loading your change order…</p>
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
            This change order link may have expired or been revoked. Please contact your design
            team for an up-to-date link.
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
            Couldn&apos;t load this change order
          </h1>
          <p className="max-w-sm text-sm text-zinc-500 dark:text-zinc-400">{loadErrorMessage}</p>
          <Button onClick={load}>Retry</Button>
        </div>
      </PortalShell>
    );
  }

  if (!data || !status) return null;

  const isResolved = status === "approved" || status === "rejected" || status === "applied";

  return (
    <PortalShell>
      <header className="border-b border-zinc-200 bg-white px-4 py-5 dark:border-zinc-800 dark:bg-zinc-900">
        <div className="mx-auto max-w-2xl">
          <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            Change Order
          </p>
          <div className="mt-1 flex items-center gap-2">
            <h1 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{data.number}</h1>
            <Badge tone={STATUS_TONE[status] ?? "neutral"}>{status}</Badge>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-2xl flex-1 space-y-6 px-4 py-6">
        {isResolved ? (
          <ResolvedBanner
            status={status}
            approvedAt={approvedAt}
            justApproved={justApproved}
            justRejected={justRejected}
          />
        ) : null}

        <ChangeOrderReason reason={data.reason} />

        <div className="space-y-3">
          <ChangeOrderPriceImpact priceDelta={data.price_delta} currency={data.organization?.currency} />
          <ChangeOrderTimelineImpact timelineDeltaDays={data.timeline_delta_days} />
        </div>

        <section>
          <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            Items
          </h2>
          <ChangeOrderItemsTable items={data.items} currency={data.organization?.currency} />
        </section>

        {!isResolved ? (
          <section className="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            {actionView === "none" ? (
              <div className="flex flex-col gap-3 sm:flex-row">
                <Button className="flex-1" onClick={() => setActionView("approve")}>
                  Approve Change
                </Button>
                <Button
                  variant="secondary"
                  className="flex-1"
                  onClick={() => setActionView("reject")}
                >
                  Reject Change
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

            {actionView === "reject" ? (
              <RejectForm
                token={token}
                onRejected={handleRejected}
                onAlreadyApproved={handleAlreadyApproved}
                onCancel={() => setActionView("none")}
              />
            ) : null}
          </section>
        ) : null}
      </main>

      <footer className="px-4 py-6 text-center text-xs text-zinc-400 dark:text-zinc-600">
        {data.sent_at ? `Sent ${formatDate(data.sent_at)}` : ""}
      </footer>
    </PortalShell>
  );
}

function ResolvedBanner({
  status,
  approvedAt,
  justApproved,
  justRejected,
}: {
  status: PublicChangeOrderStatus;
  approvedAt: string | null;
  justApproved: boolean;
  justRejected: boolean;
}) {
  if (status === "approved") {
    return (
      <div className="rounded-lg border border-green-200 bg-green-50 p-4 text-green-900 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
        <p className="text-base font-semibold">
          {justApproved ? "Approved! Thank you." : "This change order has been approved."}
        </p>
        <p className="mt-1 text-sm">
          {approvedAt ? `Approved on ${formatDate(approvedAt)}. ` : ""}
          Our team will follow up on next steps.
        </p>
      </div>
    );
  }

  if (status === "applied") {
    return (
      <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300">
        <p className="text-base font-semibold">This change has been approved and applied.</p>
        <p className="mt-1 text-sm">
          {approvedAt ? `Approved on ${formatDate(approvedAt)}. ` : ""}
          The updated scope and pricing are now reflected in your project.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
      <p className="text-base font-semibold">
        {justRejected ? "We've received your response. Thank you!" : "This change order has been rejected."}
      </p>
      <p className="mt-1 text-sm">Our team will follow up if a revised change is needed.</p>
    </div>
  );
}

function PortalShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col bg-zinc-50 dark:bg-zinc-950">{children}</div>
  );
}
