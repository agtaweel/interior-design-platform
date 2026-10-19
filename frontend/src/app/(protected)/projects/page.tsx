"use client";

/**
 * Project list — supports the "Projects" nav item and the dashboard's "view all" link.
 * Not a numbered Sprint 1 screen itself (S06 is the project *overview* detail), but a natural,
 * low-cost companion given GET /projects already exists.
 *
 * Creating a project happens in the wizard at /projects/new (docs/SIMPLIFICATION_REVIEW.md P2.1);
 * the old inline form is gone. Legacy entry links still work: ?new=1 and the marketplace
 * hand-off's ?new_client_id= redirect into the wizard.
 */

import { Suspense, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { listProjects } from "@/lib/api/resources/projects";
import type { Project, ProjectStatus } from "@/lib/api/types";
import { Card, CardBody } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatDate } from "@/lib/format/date";

const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

const STATUS_FILTERS: Array<ProjectStatus | "all"> = [
  "all",
  "draft",
  "active",
  "on_hold",
  "completed",
  "cancelled",
];

export default function ProjectsPage() {
  return (
    <Suspense fallback={<LoadingScreen label="Loading…" />}>
      <ProjectsPageContent />
    </Suspense>
  );
}

function ProjectsPageContent() {
  const { t, locale } = useLocale();
  const router = useRouter();
  const searchParams = useSearchParams();
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // ?status= pre-filters the list (the dashboard's "View all" on active projects).
  const [statusFilter, setStatusFilter] = useState<ProjectStatus | "all">(
    () => (searchParams.get("status") as ProjectStatus | null) ?? "all",
  );

  // Legacy "open the create form" links → the wizard.
  const legacyClientId = searchParams.get("new_client_id");
  const legacyNew = searchParams.get("new") === "1" || legacyClientId !== null;
  useEffect(() => {
    if (legacyNew) router.replace(legacyClientId ? `/projects/new?client_id=${encodeURIComponent(legacyClientId)}` : "/projects/new");
  }, [legacyNew, legacyClientId, router]);

  async function load(status: ProjectStatus | "all") {
    setLoading(true);
    setError(null);
    try {
      const result = await listProjects(status === "all" ? {} : { status });
      setProjects(result.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount/on-filter-change, see dashboard/page.tsx for rationale.
  useEffect(() => {
    if (legacyNew) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load(statusFilter);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter]);

  if (legacyNew) return <LoadingScreen label={t("common.loading")} />;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
          {t("nav.projects")}
        </h1>
        <div className="flex flex-wrap items-center gap-3">
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as ProjectStatus | "all")}
            className={INPUT_CLASSES}
          >
            {STATUS_FILTERS.map((status) => (
              <option key={status} value={status}>
                {status === "all" ? "All statuses" : status.replace(/_/g, " ")}
              </option>
            ))}
          </select>
          <Link
            href="/projects/new"
            className="inline-flex items-center justify-center rounded-md bg-zinc-950 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
          >
            {t("projects.new.cta")}
          </Link>
        </div>
      </div>

      {error ? (
        <ErrorBanner message={error} onRetry={() => load(statusFilter)} retryLabel={t("common.retry")} />
      ) : null}

      <Card>
        <CardBody className="p-0">
          {loading ? (
            <LoadingScreen label={t("common.loading")} />
          ) : projects.length === 0 ? (
            <EmptyState message="No projects match this filter." />
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
                        {project.is_sample ? (
                          <span className="ms-2 rounded bg-sky-100 px-1.5 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-950 dark:text-sky-300">
                            {t("sample.badge")}
                          </span>
                        ) : null}
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
    </div>
  );
}
