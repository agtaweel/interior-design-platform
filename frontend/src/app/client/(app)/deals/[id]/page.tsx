"use client";

/**
 * BRD v4 "Client Marketplace" Phase C — a marketplace client's own deal detail. Reuses the same
 * content/items/grand-total presentational components the anonymous public proposal portal
 * already built (ProposalContentSections/ProposalItemsTable/ProposalGrandTotal — pure
 * presentational, no auth-adjacent imports) since GET /client/deals/{id} returns the exact same
 * PublicProposalResource shape as GET /public/proposals/{token}. What's different here is the
 * approve/request-changes action itself: no name field (comes from the authenticated
 * ClientUser) and no OTP field at all — the locked-in, user-confirmed decision to skip OTP for
 * this logged-in flow (see config('fitout.marketplace_deal_requires_otp')'s docblock on the
 * backend).
 */

import { useEffect, useState, type FormEvent } from "react";
import { useParams } from "next/navigation";
import {
  ProposalContentSections,
  ProposalGrandTotal,
  ProposalItemsTable,
} from "@/app/p/proposals/[token]/ProposalContentSections";
import { approveClientDeal, getClientDeal, requestClientDealChanges } from "@/lib/api/resources/clientDeals";
import type { PublicProposalData, PublicProposalStatus } from "@/lib/api/publicTypes";
import { ApiError } from "@/lib/api/client";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { StatusBadge } from "@/components/ui/Badge";
import { formatDate } from "@/lib/format/date";

type ActionView = "none" | "approve" | "request-changes";

export default function ClientDealDetailPage() {
  const params = useParams<{ id: string }>();
  const [deal, setDeal] = useState<PublicProposalData | null>(null);
  const [status, setStatus] = useState<PublicProposalStatus | null>(null);
  const [approvedAt, setApprovedAt] = useState<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionView, setActionView] = useState<ActionView>("none");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  async function load() {
    try {
      const data = await getClientDeal(params.id);
      setDeal(data);
      setStatus(data.proposal.status);
      setApprovedAt(data.proposal.approved_at);
      setLoadError(null);
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : "Could not load this deal.");
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id]);

  async function handleApprove(e: FormEvent) {
    e.preventDefault();
    setActionError(null);
    setSubmitting(true);
    try {
      const result = await approveClientDeal(params.id, comment.trim() || undefined);
      setStatus("approved");
      setApprovedAt(result.approved_at);
      setActionView("none");
      setComment("");
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : "Could not approve this deal. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleRequestChanges(e: FormEvent) {
    e.preventDefault();
    setActionError(null);
    setSubmitting(true);
    try {
      await requestClientDealChanges(params.id, comment.trim());
      setStatus("changes_requested");
      setActionView("none");
      setComment("");
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : "Could not send your request. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  if (loadError) {
    return <ErrorBanner message={loadError} onRetry={load} retryLabel="Retry" />;
  }

  if (!deal || !status) {
    return <LoadingScreen label="Loading…" />;
  }

  const currency = deal.organization?.currency ?? "EGP";

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
            Deal v{deal.proposal.version_no}
          </h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{deal.project.name}</p>
        </div>
        <StatusBadge status={status} />
      </div>

      <ProposalContentSections content={deal.content} />
      <ProposalItemsTable items={deal.items} currency={currency} />
      <ProposalGrandTotal grandTotal={deal.pricing.grand_total} currency={currency} />

      {status === "approved" ? (
        <div className="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
          Approved{approvedAt ? ` on ${formatDate(approvedAt)}` : ""}.
        </div>
      ) : status === "changes_requested" ? (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300">
          You requested changes to this deal. The studio has been notified.
        </div>
      ) : actionView === "none" ? (
        <div className="flex gap-3">
          <Button onClick={() => setActionView("approve")}>Approve Deal</Button>
          <Button variant="secondary" onClick={() => setActionView("request-changes")}>
            Request Changes
          </Button>
        </div>
      ) : (
        <form
          onSubmit={actionView === "approve" ? handleApprove : handleRequestChanges}
          className="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800"
        >
          <label className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
            {actionView === "approve" ? "Comment (optional)" : "What would you like changed?"}
          </label>
          <textarea
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            required={actionView === "request-changes"}
            rows={3}
            maxLength={2000}
            className="rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950"
          />
          {actionError ? <ErrorBanner message={actionError} /> : null}
          <div className="flex gap-3">
            <Button type="submit" disabled={submitting || (actionView === "request-changes" && !comment.trim())}>
              {submitting
                ? "Submitting…"
                : actionView === "approve"
                  ? "Confirm Approval"
                  : "Send Request"}
            </Button>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setActionView("none");
                setComment("");
                setActionError(null);
              }}
              disabled={submitting}
            >
              Cancel
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}
