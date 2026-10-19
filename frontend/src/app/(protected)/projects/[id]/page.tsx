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

import { NextStepCard } from "@/components/projects/NextStepCard";
import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import {
  getClientPortalLink,
  getProject,
  issueClientPortalLink,
  revokeClientPortalLinks,
  type ClientPortalLink,
} from "@/lib/api/resources/projects";
import { WhatsAppShare } from "@/components/share/WhatsAppShare";
import type { Project } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
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

      <NextStepCard projectId={project.id} />

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
                value={
                  project.property?.compound ||
                  project.property?.address ||
                  // Places added through the new-project wizard often have only a type.
                  (project.property?.type ? t(`property.type.${project.property.type}` as TranslationKey) : t("common.na"))
                }
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
 * The client's one link (docs/SIMPLIFICATION_REVIEW.md P3.1): their project page, where they read
 * every document and approve with the codes you send. The link can be copied again at any time;
 * sending a proposal or change order creates it automatically if it doesn't exist yet. Revoking
 * closes every portal link of the project (e.g. if one leaked); the next send or "Generate" makes
 * a new one. Hidden for roles without manage_projects (the GET 403s).
 */
function ClientPortalCard({ projectId, t }: { projectId: string; t: ReturnType<typeof useLocale>["t"] }) {
  // undefined = loading; null = no link yet.
  const [link, setLink] = useState<ClientPortalLink | null | undefined>(undefined);
  const [forbidden, setForbidden] = useState(false);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);
  const [revoked, setRevoked] = useState(false);

  useEffect(() => {
    let cancelled = false;
    getClientPortalLink(projectId)
      .then((current) => !cancelled && setLink(current))
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 403) setForbidden(true);
        setLink(null);
      });
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  async function handleGenerate() {
    setBusy(true);
    setRevoked(false);
    try {
      setLink(await issueClientPortalLink(projectId));
    } finally {
      setBusy(false);
    }
  }

  async function handleRevoke() {
    if (!window.confirm(t("project.clientPortal.revokeConfirm"))) return;
    setBusy(true);
    try {
      await revokeClientPortalLinks(projectId);
      setLink(null);
      setRevoked(true);
    } finally {
      setBusy(false);
    }
  }

  async function handleCopy() {
    if (!link) return;
    try {
      await navigator.clipboard.writeText(link.url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard API unavailable — the value is still selectable in the input below.
    }
  }

  if (forbidden) {
    return <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("project.clientPortal.noAccess")}</p>;
  }
  if (link === undefined) return <p className="text-sm text-zinc-400">{t("common.loading")}</p>;

  if (!link) {
    return (
      <div className="flex flex-col gap-3 text-sm">
        {revoked ? <p className="text-xs text-amber-600 dark:text-amber-400">{t("project.clientPortal.revokedNote")}</p> : null}
        <p className="text-zinc-500 dark:text-zinc-400">{t("project.clientPortal.description")}</p>
        <Button type="button" onClick={handleGenerate} disabled={busy}>
          {t("project.clientPortal.generate")}
        </Button>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3 text-sm">
      <p className="text-zinc-500 dark:text-zinc-400">{t("project.clientPortal.oneLink")}</p>
      <div className="flex items-center gap-2">
        <input
          readOnly
          dir="ltr"
          aria-label={t("project.clientPortal.linkLabel")}
          value={link.url}
          onFocus={(e) => e.currentTarget.select()}
          className="w-full min-w-0 rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
        />
        <Button type="button" variant="secondary" className="shrink-0 py-2" onClick={handleCopy}>
          {copied ? t("changeOrders.send.copied") : t("changeOrders.send.copy")}
        </Button>
      </div>
      <WhatsAppShare kind="portal" projectId={projectId} url={link.url} />
      <button
        type="button"
        onClick={handleRevoke}
        disabled={busy}
        className="self-start text-xs text-zinc-500 underline underline-offset-2 hover:text-red-700 dark:text-zinc-400"
      >
        {t("project.clientPortal.revoke")}
      </button>
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
