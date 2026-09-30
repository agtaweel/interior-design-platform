"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { getPlatformOrganization } from "@/lib/api/resources/platform";
import type { PlatformOrganizationDetail } from "@/lib/api/platformTypes";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { formatEGPOrDash } from "@/lib/format/currency";
import { formatDate } from "@/lib/format/date";
import { useLocale } from "@/lib/i18n/LocaleProvider";

export default function PlatformOrganizationDetailPage() {
  const params = useParams<{ id: string }>();
  const { t, locale } = useLocale();
  const [org, setOrg] = useState<PlatformOrganizationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      setOrg(await getPlatformOrganization(params.id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id]);

  if (loading) return <LoadingScreen label={t("common.loading")} />;
  if (error) return <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} />;
  if (!org) return null;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link href="/platform" className="text-sm text-zinc-500 hover:underline dark:text-zinc-400">
          ← Platform Overview
        </Link>
        <h1 className="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{org.name}</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          {org.legal_name ?? org.name} · {org.currency} · Joined {formatDate(org.created_at, locale)}
        </p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <StatCard tone="violet" label="Projects" value={String(org.projects_count)} />
        <StatCard tone="blue" label="Members" value={String(org.members_count)} />
        <StatCard tone="rose" label="Outstanding" value={formatEGPOrDash(org.financials.outstanding, locale)} />
        <StatCard tone="green" label="Gross Profit" value={formatEGPOrDash(org.financials.gross_profit, locale)} />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Financials</h2>
          </CardHeader>
          <CardBody className="flex flex-col gap-3 text-sm">
            <Field label="Obligation" value={formatEGPOrDash(org.financials.obligation, locale)} />
            <Field label="Paid" value={formatEGPOrDash(org.financials.paid, locale)} />
            <Field label="Outstanding" value={formatEGPOrDash(org.financials.outstanding, locale)} />
            <Field label="Actual Cost" value={formatEGPOrDash(org.financials.actual_cost, locale)} />
            <Field label="Gross Profit" value={formatEGPOrDash(org.financials.gross_profit, locale)} />
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Projects by Status</h2>
          </CardHeader>
          <CardBody className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            {Object.entries(org.projects_by_status).map(([status, count]) => (
              <Field key={status} label={status.replace(/_/g, " ")} value={String(count)} />
            ))}
          </CardBody>
        </Card>
      </div>
    </div>
  );
}

// Hex, not Tailwind color utilities — see KpiCard's identical comment: a plain
// `border-t-{color}` utility reliably loses to this div's own `dark:border-zinc-800`.
const STAT_TONE_HEX: Record<string, string> = {
  amber: "#f59e0b",
  blue: "#3b82f6",
  green: "#10b981",
  violet: "#8b5cf6",
  rose: "#f43f5e",
};

function StatCard({ label, value, tone }: { label: string; value: string; tone?: keyof typeof STAT_TONE_HEX }) {
  return (
    <div
      className="rounded-lg border border-t-4 border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
      style={tone ? { borderTopColor: STAT_TONE_HEX[tone] } : undefined}
    >
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{label}</p>
      <p className="mt-1 text-xl font-semibold text-zinc-900 dark:text-zinc-50">{value}</p>
    </div>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400 capitalize">{label}</p>
      <p className="mt-0.5 text-zinc-900 dark:text-zinc-100">{value}</p>
    </div>
  );
}
