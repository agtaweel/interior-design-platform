"use client";

/**
 * BRD v4 "Client Marketplace" Phase C — a marketplace client's own project detail. One
 * consolidated page (overview + contract + payments + change orders) rather than separate
 * sub-routes — the approved plan only calls for one `/client/projects/[id]` route, and the data
 * volume per project here is small enough that tabs/sub-pages would be pure overhead. Mirrors
 * PublicClientPortalController's own redaction rules: only value/collected/outstanding ever
 * rendered, never cost/margin — see that controller's docblock and ClientProjectController's
 * (which this page's data comes from).
 */

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import {
  getClientProjectContract,
  getClientProjectOverview,
  listClientProjectChangeOrders,
  listClientProjectPayments,
  type ClientChangeOrder,
} from "@/lib/api/resources/clientProjects";
import type { ClientProjectOverview, Contract, Payment } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { formatEGP } from "@/lib/format/currency";
import { formatDate } from "@/lib/format/date";

export default function ClientProjectDetailPage() {
  const params = useParams<{ id: string }>();
  const [overview, setOverview] = useState<ClientProjectOverview | null>(null);
  const [contract, setContract] = useState<Contract | null>(null);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [changeOrders, setChangeOrders] = useState<ClientChangeOrder[]>([]);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    try {
      const data = await getClientProjectOverview(params.id);
      setOverview(data);

      const [contractResult, paymentsResult, changeOrdersResult] = await Promise.allSettled([
        getClientProjectContract(params.id),
        listClientProjectPayments(params.id),
        listClientProjectChangeOrders(params.id),
      ]);
      if (contractResult.status === "fulfilled") setContract(contractResult.value);
      if (paymentsResult.status === "fulfilled") setPayments(paymentsResult.value);
      if (changeOrdersResult.status === "fulfilled") setChangeOrders(changeOrdersResult.value);
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this project.");
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id]);

  if (error) {
    return <ErrorBanner message={error} onRetry={load} retryLabel="Retry" />;
  }

  if (!overview) {
    return <LoadingScreen label="Loading…" />;
  }

  const currency = overview.organization.currency;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{overview.project.name}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {formatDate(overview.project.start_date)} – {formatDate(overview.project.target_end_date)}
          </p>
        </div>
        <StatusBadge status={overview.project.status} />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card>
          <CardBody>
            <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
              Project Value
            </p>
            <p className="mt-1 text-xl font-semibold text-zinc-900 dark:text-zinc-50">
              {formatEGP(overview.financials.value, "en", currency)}
            </p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
              Collected
            </p>
            <p className="mt-1 text-xl font-semibold text-zinc-900 dark:text-zinc-50">
              {formatEGP(overview.financials.collected, "en", currency)}
            </p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
              Outstanding
            </p>
            <p className="mt-1 text-xl font-semibold text-zinc-900 dark:text-zinc-50">
              {formatEGP(overview.financials.outstanding, "en", currency)}
            </p>
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Contract</h2>
        </CardHeader>
        <CardBody>
          {contract ? (
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="font-medium text-zinc-900 dark:text-zinc-50">{contract.contract_no}</p>
                <p className="text-sm text-zinc-500 dark:text-zinc-400">Signed {formatDate(contract.signed_at)}</p>
              </div>
              <p className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
                {formatEGP(contract.contract_value, "en", currency)}
              </p>
            </div>
          ) : (
            <EmptyState message="No contract yet." />
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Payments</h2>
        </CardHeader>
        <CardBody className="p-0">
          {payments.length === 0 ? (
            <div className="p-4">
              <EmptyState message="No payments recorded yet." />
            </div>
          ) : (
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {payments.map((p) => (
                <li key={p.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div>
                    <p className="text-sm text-zinc-900 dark:text-zinc-50">{formatDate(p.paid_at)}</p>
                    <p className="text-xs text-zinc-500 dark:text-zinc-400">
                      {p.payment_method.replace(/_/g, " ")}
                    </p>
                  </div>
                  <p className="font-medium text-zinc-900 dark:text-zinc-50">
                    {formatEGP(p.amount, "en", currency)}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Change Orders</h2>
        </CardHeader>
        <CardBody className="p-0">
          {changeOrders.length === 0 ? (
            <div className="p-4">
              <EmptyState message="No change orders yet." />
            </div>
          ) : (
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {changeOrders.map((co) => (
                <li key={co.number} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div>
                    <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{co.number}</p>
                    <p className="text-xs text-zinc-500 dark:text-zinc-400">{co.reason}</p>
                  </div>
                  <div className="text-end">
                    <StatusBadge status={co.status} />
                    {co.price_delta !== null ? (
                      <p className="mt-1 text-sm font-medium text-zinc-900 dark:text-zinc-50">
                        {formatEGP(co.price_delta, "en", currency)}
                      </p>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
