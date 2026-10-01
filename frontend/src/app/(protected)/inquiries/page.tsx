"use client";

/**
 * BRD v4 "Client Marketplace" Phase B — staff inbox for conversations marketplace clients
 * started with this organization. Same 60s-poll pattern as NotificationBell.tsx.
 */

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { listInquiries } from "@/lib/api/resources/inquiries";
import type { InquirySummary } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatDateTime } from "@/lib/format/date";

const POLL_INTERVAL_MS = 60_000;

export default function InquiriesPage() {
  const { t, locale } = useLocale();
  const [inquiries, setInquiries] = useState<InquirySummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setInquiries(await listInquiries());
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inquiries.loadFailed"));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- t is stable enough for this purpose
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  useEffect(() => {
    const interval = setInterval(load, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [load]);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("inquiries.title")}</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("inquiries.subtitle")}</p>
      </div>

      {error ? <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {inquiries === null && !error ? (
        <LoadingScreen label={t("common.loading")} />
      ) : inquiries && inquiries.length === 0 ? (
        <EmptyState message={t("inquiries.empty")} />
      ) : (
        <Card>
          <CardBody className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm rtl:text-right">
                <thead className="border-b border-zinc-200 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                  <tr>
                    <th className="whitespace-nowrap px-4 py-2 font-medium">{t("inquiries.table.columns.client")}</th>
                    <th className="whitespace-nowrap px-4 py-2 font-medium">{t("inquiries.table.columns.lastMessage")}</th>
                    <th className="whitespace-nowrap px-4 py-2 font-medium">{t("inquiries.table.columns.updated")}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                  {inquiries?.map((inquiry) => (
                    <tr key={inquiry.id} className="hover:bg-zinc-50 dark:hover:bg-zinc-900">
                      <td className="whitespace-nowrap px-4 py-2">
                        <Link href={`/inquiries/${inquiry.id}`} className="font-medium text-zinc-900 hover:underline dark:text-zinc-50">
                          {inquiry.client.name}
                        </Link>
                        <p className="text-xs text-zinc-500 dark:text-zinc-400">{inquiry.client.email}</p>
                      </td>
                      <td className="max-w-xs truncate px-4 py-2 text-zinc-500 dark:text-zinc-400">
                        {inquiry.last_message?.body ?? "—"}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 text-zinc-500 dark:text-zinc-400">
                        {formatDateTime(inquiry.updated_at, locale)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardBody>
        </Card>
      )}
    </div>
  );
}
