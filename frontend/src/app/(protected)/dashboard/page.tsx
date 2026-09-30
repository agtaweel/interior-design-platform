"use client";

/**
 * S02 — Main Dashboard.
 *
 * Active project count comes from GET /projects?status=active (meta.total). Pending
 * proposals / receivables / monthly revenue have no backing API yet (proposals/payments are
 * Sprint 4/6 scope per docs/PROJECT_CONTEXT.md's MVP delivery order) — stubbed as "—" rather
 * than a fabricated 0, so the UI is honest about "no data source yet" vs. "confirmed zero".
 * Overdue items has no backing API yet either (depends on payment schedules / tasks, both
 * later sprints) — stubbed as an empty section.
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { listProjects } from "@/lib/api/resources/projects";
import type { Project } from "@/lib/api/types";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { KpiCard } from "@/components/ui/KpiCard";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { formatDate } from "@/lib/format/date";

export default function DashboardPage() {
  const { t, locale } = useLocale();
  const [activeProjectsCount, setActiveProjectsCount] = useState<number | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const [activeResult, recentResult] = await Promise.all([
        listProjects({ status: "active" }),
        listProjects(),
      ]);
      setActiveProjectsCount(activeResult.meta.total);
      setProjects(recentResult.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount: load() sets loading/error/data state from the API response,
  // the standard pattern for this app's data screens (see also clients/projects/properties
  // pages, which share it).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- load() only depends on stable imports
  }, []);

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
        {t("dashboard.title")}
      </h1>

      {error ? <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} /> : null}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard
          tone="blue"
          label={t("dashboard.kpi.activeProjects")}
          value={activeProjectsCount === null ? "—" : String(activeProjectsCount)}
        />
        <KpiCard tone="amber" label={t("dashboard.kpi.pendingProposals")} value="—" hint="Sprint 4" />
        <KpiCard tone="rose" label={t("dashboard.kpi.receivables")} value="—" hint="Sprint 6" />
        <KpiCard tone="green" label={t("dashboard.kpi.monthlyRevenue")} value="—" hint="Sprint 6" />
      </div>

      <Card>
        <CardHeader className="flex items-center justify-between">
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
            {t("dashboard.projects.title")}
          </h2>
          <Link href="/projects" className="text-xs font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100">
            {t("dashboard.viewAll")}
          </Link>
        </CardHeader>
        <CardBody className="p-0">
          {loading ? (
            <LoadingScreen label={t("common.loading")} />
          ) : projects.length === 0 ? (
            <EmptyState message={t("dashboard.projects.empty")} />
          ) : (
            <ul className="divide-y divide-zinc-200 dark:divide-zinc-800">
              {projects.map((project) => (
                <li key={project.id}>
                  <Link
                    href={`/projects/${project.id}`}
                    className="flex items-center justify-between gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                  >
                    <div>
                      <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
                        {project.name}
                      </p>
                      <p className="text-xs text-zinc-500 dark:text-zinc-400">
                        {project.client?.name ?? t("common.na")} · {project.code ?? "—"}
                      </p>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className="text-xs text-zinc-400">
                        {formatDate(project.target_end_date, locale)}
                      </span>
                      <StatusBadge status={project.status} />
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
            {t("dashboard.overdue.title")}
          </h2>
        </CardHeader>
        <CardBody>
          <EmptyState message={t("dashboard.overdue.empty")} />
        </CardBody>
      </Card>
    </div>
  );
}
