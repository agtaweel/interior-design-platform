"use client";

/**
 * S06 — Project Overview.
 *
 * Status, value/collected/outstanding/actual cost/profit come straight from GET /projects/{id}
 * `financials` — all placeholders (0/null) for Sprint 1 per ProjectResource's docblock; this
 * screen renders them via the shared EGP formatter regardless, so no code change is needed once
 * later sprints populate real numbers. "Progress" and "Recent activity" have no backing data
 * source yet (execution/tasks and audit-log surfacing are later-sprint scope) — both stubbed.
 */

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { getProject } from "@/lib/api/resources/projects";
import type { Project } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatDate } from "@/lib/format/date";
import { formatEGPOrDash } from "@/lib/format/currency";

export default function ProjectOverviewPage() {
  const params = useParams<{ id: string }>();
  const { t, locale } = useLocale();
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
            <Field label={t("project.financials.value")} value={formatEGPOrDash(financials.value, locale)} />
            <Field
              label={t("project.financials.collected")}
              value={formatEGPOrDash(financials.collected, locale)}
            />
            <Field
              label={t("project.financials.outstanding")}
              value={formatEGPOrDash(financials.outstanding, locale)}
            />
            <Field
              label={t("project.financials.actualCost")}
              value={formatEGPOrDash(financials.actual_cost, locale)}
            />
            <Field
              label={t("project.financials.grossProfit")}
              value={formatEGPOrDash(financials.gross_profit, locale)}
            />
          </CardBody>
        </Card>
      </div>
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
