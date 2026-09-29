"use client";

/**
 * BRD v3 §5/§20 "Platform Owner / Super Admin" — cross-tenant analytics dashboard. Only a user
 * with `is_platform_owner` can load this successfully (EnsurePlatformOwner on the backend
 * returns 403 for everyone else); this page doesn't itself redirect non-owners away — the API
 * call simply fails and the error state renders, same "let the API be the source of truth for
 * authorization" posture the rest of this app already uses (e.g. a 403 from a
 * Permissions::MANAGE_BOQ-gated endpoint isn't pre-empted by frontend role-checking either).
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { getPlatformOrganizations, getPlatformSummary } from "@/lib/api/resources/platform";
import type { PlatformOrganizationSummary, PlatformSummary } from "@/lib/api/platformTypes";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { formatEGPOrDash } from "@/lib/format/currency";
import { useLocale } from "@/lib/i18n/LocaleProvider";

export default function PlatformDashboardPage() {
  const { t, locale } = useLocale();
  const [summary, setSummary] = useState<PlatformSummary | null>(null);
  const [organizations, setOrganizations] = useState<PlatformOrganizationSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const [summaryResult, organizationsResult] = await Promise.all([
        getPlatformSummary(),
        getPlatformOrganizations(),
      ]);
      setSummary(summaryResult);
      setOrganizations(organizationsResult);
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
  }, []);

  if (loading) return <LoadingScreen label={t("common.loading")} />;
  if (error) return <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} />;
  if (!summary) return null;

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Platform Overview</h1>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <StatCard label="Organizations" value={String(summary.organizations_count)} />
        <StatCard label="Projects" value={String(summary.projects_count)} />
        <StatCard label="Users" value={String(summary.users_count)} />
        <StatCard label="Outstanding" value={formatEGPOrDash(summary.financials.outstanding, locale)} />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Platform Financials</h2>
          </CardHeader>
          <CardBody className="flex flex-col gap-3 text-sm">
            <Field label="Obligation" value={formatEGPOrDash(summary.financials.obligation, locale)} />
            <Field label="Paid" value={formatEGPOrDash(summary.financials.paid, locale)} />
            <Field label="Outstanding" value={formatEGPOrDash(summary.financials.outstanding, locale)} />
            <Field label="Actual Cost" value={formatEGPOrDash(summary.financials.actual_cost, locale)} />
            <Field label="Gross Profit" value={formatEGPOrDash(summary.financials.gross_profit, locale)} />
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Projects by Status</h2>
          </CardHeader>
          <CardBody className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            {Object.entries(summary.projects_by_status).map(([status, count]) => (
              <Field key={status} label={status.replace(/_/g, " ")} value={String(count)} />
            ))}
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Organizations</h2>
        </CardHeader>
        <CardBody className="p-0">
          <div className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {organizations.map((org) => (
              <Link
                key={org.id}
                href={`/platform/${org.id}`}
                className="flex items-center justify-between px-4 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800"
              >
                <div>
                  <p className="font-medium text-zinc-900 dark:text-zinc-50">{org.name}</p>
                  <p className="text-xs text-zinc-500 dark:text-zinc-400">
                    {org.projects_count} projects · {org.members_count} members
                  </p>
                </div>
                <span className="text-xs text-zinc-400">{org.currency}</span>
              </Link>
            ))}
          </div>
        </CardBody>
      </Card>
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
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
