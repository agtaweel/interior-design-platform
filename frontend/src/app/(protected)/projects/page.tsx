"use client";

/**
 * Project list — supports the "Projects" nav item and the dashboard's "view all" link.
 * Not a numbered Sprint 1 screen itself (S06 is the project *overview* detail), but a natural,
 * low-cost companion given GET /projects already exists.
 */

import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { listProjects, createProject } from "@/lib/api/resources/projects";
import { listClients, getClient } from "@/lib/api/resources/clients";
import type { Client, Project, ProjectStatus, PropertySummary } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
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
  const { t, locale } = useLocale();
  const router = useRouter();
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<ProjectStatus | "all">("all");

  const [showNewForm, setShowNewForm] = useState(false);
  const [clients, setClients] = useState<Client[]>([]);
  const [clientsLoading, setClientsLoading] = useState(false);
  const [clientId, setClientId] = useState("");
  const [properties, setProperties] = useState<PropertySummary[]>([]);
  const [propertiesLoading, setPropertiesLoading] = useState(false);
  const [propertyId, setPropertyId] = useState("");
  const [name, setName] = useState("");
  const [startDate, setStartDate] = useState("");
  const [targetEndDate, setTargetEndDate] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

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
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load(statusFilter);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter]);

  async function loadClients() {
    setClientsLoading(true);
    try {
      const result = await listClients({});
      setClients(result.data);
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setClientsLoading(false);
    }
  }

  // Fetch the client list once, the first time the form is opened.
  useEffect(() => {
    if (showNewForm && clients.length === 0 && !clientsLoading) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      loadClients();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showNewForm]);

  // No standalone "list properties for a client" endpoint exists — GET /clients/{id} is the
  // only place the API surfaces a client's properties (see clients.ts docblock), so re-fetch
  // the client whenever the selection changes to (re)populate the property options.
  useEffect(() => {
    if (!clientId) {
      // Intentional reset when the client selection is cleared.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setProperties([]);
      setPropertyId("");
      return;
    }
    let cancelled = false;
    setPropertiesLoading(true);
    setPropertyId("");
    getClient(clientId)
      .then((client) => {
        if (!cancelled) setProperties(client.properties ?? []);
      })
      .catch((err) => {
        if (!cancelled) {
          setFormError(err instanceof ApiError ? err.message : t("common.unknownError"));
        }
      })
      .finally(() => {
        if (!cancelled) setPropertiesLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [clientId]);

  function resetNewForm() {
    setClientId("");
    setPropertyId("");
    setProperties([]);
    setName("");
    setStartDate("");
    setTargetEndDate("");
    setFormError(null);
  }

  async function handleCreate(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    setSubmitting(true);
    try {
      const project = await createProject({
        client_id: clientId,
        property_id: propertyId || undefined,
        name,
        start_date: startDate || undefined,
        target_end_date: targetEndDate || undefined,
      });
      resetNewForm();
      setShowNewForm(false);
      router.push(`/projects/${project.id}`);
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

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
          <Button
            type="button"
            onClick={() => {
              setShowNewForm((prev) => !prev);
              setFormError(null);
            }}
          >
            {t("projects.new.cta")}
          </Button>
        </div>
      </div>

      {error ? (
        <ErrorBanner message={error} onRetry={() => load(statusFilter)} retryLabel={t("common.retry")} />
      ) : null}

      {showNewForm ? (
        <Card>
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
              {t("projects.new.title")}
            </h2>
          </CardHeader>
          <CardBody>
            <form onSubmit={handleCreate} className="flex flex-col gap-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label={t("project.details.client")} htmlFor="project-client" required>
                  <select
                    id="project-client"
                    required
                    value={clientId}
                    onChange={(e) => setClientId(e.target.value)}
                    disabled={clientsLoading}
                    className={INPUT_CLASSES}
                  >
                    <option value="" disabled>
                      {t("projects.new.selectClient")}
                    </option>
                    {clients.map((client) => (
                      <option key={client.id} value={client.id}>
                        {client.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label={t("project.details.property")} htmlFor="project-property">
                  <select
                    id="project-property"
                    value={propertyId}
                    onChange={(e) => setPropertyId(e.target.value)}
                    disabled={!clientId || propertiesLoading}
                    className={INPUT_CLASSES}
                  >
                    <option value="">{t("projects.new.selectProperty")}</option>
                    {properties.map((property) => (
                      <option key={property.id} value={property.id}>
                        {property.compound ?? property.address ?? property.type ?? property.id}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label={t("project.form.name")} htmlFor="project-name" required>
                  <input
                    id="project-name"
                    type="text"
                    required
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className={INPUT_CLASSES}
                  />
                </Field>
                <div className="grid grid-cols-2 gap-4">
                  <Field label={t("project.details.startDate")} htmlFor="project-start-date">
                    <input
                      id="project-start-date"
                      type="date"
                      value={startDate}
                      onChange={(e) => setStartDate(e.target.value)}
                      className={INPUT_CLASSES}
                    />
                  </Field>
                  <Field label={t("project.details.targetEndDate")} htmlFor="project-end-date">
                    <input
                      id="project-end-date"
                      type="date"
                      value={targetEndDate}
                      onChange={(e) => setTargetEndDate(e.target.value)}
                      className={INPUT_CLASSES}
                    />
                  </Field>
                </div>
              </div>

              {formError ? <ErrorBanner message={formError} /> : null}

              <div className="flex items-center gap-3">
                <Button type="submit" disabled={submitting}>
                  {submitting ? t("projects.new.submitting") : t("projects.new.submit")}
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => {
                    setShowNewForm(false);
                    resetNewForm();
                  }}
                >
                  {t("common.cancel")}
                </Button>
              </div>
            </form>
          </CardBody>
        </Card>
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

function Field({
  label,
  htmlFor,
  required,
  children,
}: {
  label: string;
  htmlFor: string;
  required?: boolean;
  children: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={htmlFor} className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
        {label}
        {required ? <span className="text-red-500"> *</span> : null}
      </label>
      {children}
    </div>
  );
}
