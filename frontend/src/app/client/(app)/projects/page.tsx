"use client";

/**
 * BRD v4 "Client Marketplace" Phase C — a marketplace client's own projects list.
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { listClientProjects } from "@/lib/api/resources/clientProjects";
import type { ClientProjectSummary } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";

export default function ClientProjectsPage() {
  const [projects, setProjects] = useState<ClientProjectSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    try {
      setProjects(await listClientProjects());
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load your projects.");
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, []);

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Projects</h1>

      {error ? <ErrorBanner message={error} onRetry={load} retryLabel="Retry" /> : null}

      {projects === null && !error ? (
        <LoadingScreen label="Loading…" />
      ) : projects && projects.length === 0 ? (
        <EmptyState message="No projects yet." />
      ) : (
        <Card>
          <CardBody className="p-0">
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {projects?.map((p) => (
                <li key={p.id}>
                  <Link
                    href={`/client/projects/${p.id}`}
                    className="flex items-center justify-between gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-900"
                  >
                    <div className="min-w-0">
                      <p className="truncate font-medium text-zinc-900 dark:text-zinc-50">{p.name}</p>
                      <p className="truncate text-sm text-zinc-500 dark:text-zinc-400">{p.code}</p>
                    </div>
                    <StatusBadge status={p.status} />
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
