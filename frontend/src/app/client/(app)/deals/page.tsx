"use client";

/**
 * BRD v4 "Client Marketplace" Phase C — a marketplace client's own deals list (the existing
 * Proposal system, relabeled "deal" for the client-facing UI per the approved plan).
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { listClientDeals } from "@/lib/api/resources/clientDeals";
import type { ClientDealSummary } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { formatEGP } from "@/lib/format/currency";

export default function ClientDealsPage() {
  const [deals, setDeals] = useState<ClientDealSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    try {
      setDeals(await listClientDeals());
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load your deals.");
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, []);

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Deals</h1>

      {error ? <ErrorBanner message={error} onRetry={load} retryLabel="Retry" /> : null}

      {deals === null && !error ? (
        <LoadingScreen label="Loading…" />
      ) : deals && deals.length === 0 ? (
        <EmptyState message="No deals yet." />
      ) : (
        <Card>
          <CardBody className="p-0">
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {deals?.map((d) => (
                <li key={d.id}>
                  <Link
                    href={`/client/deals/${d.id}`}
                    className="flex items-center justify-between gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-900"
                  >
                    <div className="min-w-0">
                      <p className="truncate font-medium text-zinc-900 dark:text-zinc-50">
                        Deal v{d.version_no}
                      </p>
                      <StatusBadge status={d.status} />
                    </div>
                    <p className="shrink-0 font-semibold text-zinc-900 dark:text-zinc-50">
                      {formatEGP(d.grand_total, "en")}
                    </p>
                  </Link>
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>
      )}
    </div>
  );
}
