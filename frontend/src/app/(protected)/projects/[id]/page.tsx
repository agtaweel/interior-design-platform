"use client";

/**
 * S06 — Project Overview.
 *
 * Status, value/collected/outstanding/actual cost/profit come straight from GET /projects/{id}
 * `financials`, rendered via the shared EGP formatter. `value` (Sprint 3), `collected` and
 * `outstanding` (Sprint 6) are real computed numbers; `actual_cost`/`gross_profit` remain
 * permanent placeholders (0/null) by design — they need real expense tracking, which is
 * explicitly out of scope for this 8-sprint MVP (see PROJECT_CONTEXT.md's Sprint 6/8 scope-
 * boundary sections). "Progress" and "Recent activity" have no backing data source (execution/
 * tasks and audit-log surfacing are Phase 2, also out of scope) — both stubbed permanently.
 */

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { getProject, issueClientPortalLink, revokeClientPortalLink } from "@/lib/api/resources/projects";
import type { Project } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatDate } from "@/lib/format/date";
import { useMoneyFormatter } from "@/lib/format/useMoneyFormatter";

export default function ProjectOverviewPage() {
  const params = useParams<{ id: string }>();
  const { t, locale } = useLocale();
  const { formatMoneyOrDash } = useMoneyFormatter();
  const [project, setProject] = useState<Project | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      setProject(await getProject(params.id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount, see dashboard/page.tsx for rationale.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id]);

  if (loading) return <LoadingScreen label={t("common.loading")} />;
  if (error) return <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} />;
  if (!project) return <EmptyState message={t("common.notFound")} />;

  const { financials } = project;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{project.name}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{project.code}</p>
        </div>
        <StatusBadge status={project.status} />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="flex flex-col gap-6 lg:col-span-2">
          <Card>
            <CardHeader>
              <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                {t("project.overview.title")}
              </h2>
            </CardHeader>
            <CardBody className="grid grid-cols-2 gap-4 text-sm">
              <Field label={t("project.details.client")} value={project.client?.name ?? t("common.na")} />
              <Field
                label={t("project.details.property")}
                value={project.property?.compound ?? project.property?.address ?? t("common.na")}
              />
              <Field
                label={t("project.details.responsible")}
                value={project.responsible_user?.name ?? t("common.na")}
              />
              <Field label={t("project.details.startDate")} value={formatDate(project.start_date, locale)} />
              <Field
                label={t("project.details.targetEndDate")}
                value={formatDate(project.target_end_date, locale)}
              />
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                {t("project.overview.progress")}
              </h2>
            </CardHeader>
            <CardBody>
              <div className="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                <div className="h-full w-0 bg-zinc-900 dark:bg-zinc-100" />
              </div>
              <p className="mt-2 text-xs text-zinc-400">{t("project.overview.progressNote")}</p>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                {t("project.activity.title")}
              </h2>
            </CardHeader>
            <CardBody>
              <EmptyState message={t("project.activity.empty")} />
            </CardBody>
          </Card>
        </div>

        <Card className="lg:col-span-1">
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("project.financials.title")}
            </h2>
          </CardHeader>
          <CardBody className="flex flex-col gap-3 text-sm">
            <Field label={t("project.financials.value")} value={formatMoneyOrDash(financials.value)} />
            <Field
              label={t("project.financials.collected")}
              value={formatMoneyOrDash(financials.collected)}
            />
            <Field
              label={t("project.financials.outstanding")}
              value={formatMoneyOrDash(financials.outstanding)}
            />
            <Field
              label={t("project.financials.actualCost")}
              value={formatMoneyOrDash(financials.actual_cost)}
            />
            <Field
              label={t("project.financials.grossProfit")}
              value={formatMoneyOrDash(financials.gross_profit)}
            />
          </CardBody>
        </Card>

        <Card className="lg:col-span-1">
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("project.clientPortal.title")}
            </h2>
          </CardHeader>
          <CardBody>
            <ClientPortalCard projectId={params.id} t={t} />
          </CardBody>
        </Card>
      </div>
    </div>
  );
}

/**
 * BRD v3 §17 "Client Portal" — generates/copies/revokes the long-lived signed link a client can
 * bookmark. The token itself is never persisted anywhere in this app after issuance (matches the
 * backend's own posture: only its sha256 hash is stored) — if staff navigate away without
 * copying it, generating again issues a brand-new token rather than exposing a "forgotten" one,
 * same one-time-visibility discipline as the proposal/change-order send flows' OTP codes.
 */
function ClientPortalCard({ projectId, t }: { projectId: string; t: ReturnType<typeof useLocale>["t"] }) {
  const [token, setToken] = useState<string | null>(null);
  const [revoked, setRevoked] = useState(false);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);

  const portalUrl = token
    ? `${typeof window !== "undefined" ? window.location.origin : ""}/p/client-portal/${token}`
    : "";

  async function handleGenerate() {
    setBusy(true);
    setRevoked(false);
    try {
      const result = await issueClientPortalLink(projectId);
      setToken(result.token);
    } finally {
      setBusy(false);
    }
  }

  async function handleRevoke() {
    if (!token) return;
    setBusy(true);
    try {
      await revokeClientPortalLink(projectId, token);
      setRevoked(true);
    } finally {
      setBusy(false);
    }
  }

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(portalUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard API unavailable — the value is still selectable in the input below.
    }
  }

  if (!token) {
    return (
      <div className="flex flex-col gap-3 text-sm">
        <p className="text-zinc-500 dark:text-zinc-400">{t("project.clientPortal.description")}</p>
        <Button type="button" onClick={handleGenerate} disabled={busy}>
          {t("project.clientPortal.generate")}
        </Button>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3 text-sm">
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">
        {t("project.clientPortal.linkLabel")}
      </p>
      <div className="flex items-center gap-2">
        <input
          readOnly
          value={portalUrl}
          onFocus={(e) => e.currentTarget.select()}
          className="w-full min-w-0 rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
        />
        <Button type="button" variant="secondary" className="shrink-0 py-2" onClick={handleCopy}>
          {copied ? t("changeOrders.send.copied") : t("changeOrders.send.copy")}
        </Button>
      </div>
      {revoked ? (
        <p className="text-xs text-amber-600 dark:text-amber-400">{t("project.clientPortal.revokedNote")}</p>
      ) : (
        <Button type="button" variant="secondary" onClick={handleRevoke} disabled={busy}>
          {t("project.clientPortal.revoke")}
        </Button>
      )}
    </div>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{label}</p>
      <p className="mt-0.5 text-zinc-900 dark:text-zinc-100">{value}</p>
    </div>
  );
}
