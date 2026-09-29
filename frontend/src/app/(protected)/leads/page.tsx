"use client";

/**
 * S03 — Leads (BRD "CRM/Leads": lead pipeline, source, budget, notes, conversion to
 * client/project). Backed by lib/api/resources/leads.ts (real API — the mock data source this
 * screen originally shipped against has been retired now that GET/POST/PATCH /leads and
 * POST /leads/{id}/convert exist).
 *
 * Pipeline columns are new/contacted/qualified/lost (movable via LeadCard's dropdown) plus a
 * fifth "converted" column for the terminal, immutable state that only the Convert action can
 * reach — matching UpdateLeadRequest's server-side restriction (see LeadController::convert()'s
 * docblock) that a lead can never be PATCHed into "converted" directly.
 */

import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react";
import { convertLead, createLead, getLeads, updateLeadStatus } from "@/lib/api/resources/leads";
import type { Lead, LeadStatus } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { LeadCard } from "@/components/leads/LeadCard";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

const PIPELINE_STATUSES: LeadStatus[] = ["new", "contacted", "qualified", "lost", "converted"];
const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";

export default function LeadsPage() {
  const { t } = useLocale();
  const [leads, setLeads] = useState<Lead[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [sourceFilter, setSourceFilter] = useState<string>("all");
  const [showCreate, setShowCreate] = useState(false);
  const [changingId, setChangingId] = useState<string | null>(null);
  const [convertTarget, setConvertTarget] = useState<Lead | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  async function load() {
    setLoadError(null);
    try {
      setLeads(await getLeads());
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  // Intentional fetch-on-mount, matching every other screen in this app (see payments/page.tsx).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, []);

  const sources = useMemo(
    () => Array.from(new Set((leads ?? []).map((l) => l.source).filter((s): s is string => !!s))).sort(),
    [leads],
  );

  const filtered = useMemo(() => {
    if (!leads) return [];
    const needle = search.trim().toLowerCase();
    return leads.filter((lead) => {
      if (needle && !lead.name.toLowerCase().includes(needle) && !(lead.phone ?? "").includes(needle)) {
        return false;
      }
      if (sourceFilter !== "all" && lead.source !== sourceFilter) return false;
      return true;
    });
  }, [leads, search, sourceFilter]);

  const byStatus = useMemo(() => {
    const map = new Map<LeadStatus, Lead[]>();
    for (const status of PIPELINE_STATUSES) map.set(status, []);
    for (const lead of filtered) map.get(lead.status)?.push(lead);
    return map;
  }, [filtered]);

  async function handleStatusChange(lead: Lead, status: Exclude<LeadStatus, "converted">) {
    setChangingId(String(lead.id));
    setActionError(null);
    try {
      const updated = await updateLeadStatus(lead.id, status);
      setLeads((prev) => (prev ?? []).map((l) => (String(l.id) === String(lead.id) ? updated : l)));
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setChangingId(null);
    }
  }

  function handleCreated(created: Lead) {
    setLeads((prev) => [created, ...(prev ?? [])]);
    setShowCreate(false);
  }

  function handleConverted(updatedLead: Lead) {
    setLeads((prev) => (prev ?? []).map((l) => (String(l.id) === String(updatedLead.id) ? updatedLead : l)));
    setConvertTarget(null);
  }

  if (leads === null && !loadError) {
    return <LoadingScreen label={t("common.loading")} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("leads.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("leads.subtitle")}</p>
        </div>
        <Button type="button" onClick={() => setShowCreate((v) => !v)}>
          {t("leads.create.cta")}
        </Button>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}
      {actionError ? <ErrorBanner message={actionError} /> : null}

      {showCreate ? (
        <Card>
          <CardBody>
            <CreateLeadForm onCreated={handleCreated} onCancel={() => setShowCreate(false)} t={t} />
          </CardBody>
        </Card>
      ) : null}

      {leads && leads.length > 0 ? (
        <div className="flex flex-wrap items-center gap-3">
          <input
            type="search"
            placeholder={t("leads.filters.search")}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className={INPUT_CLASSES}
          />
          <select
            value={sourceFilter}
            onChange={(e) => setSourceFilter(e.target.value)}
            className={INPUT_CLASSES}
            aria-label={t("leads.filters.source")}
          >
            <option value="all">{t("leads.filters.allSources")}</option>
            {sources.map((source) => (
              <option key={source} value={source}>
                {source}
              </option>
            ))}
          </select>
        </div>
      ) : null}

      {leads && leads.length === 0 ? (
        <EmptyState message={t("leads.empty")} />
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
          {PIPELINE_STATUSES.map((status) => {
            const columnLeads = byStatus.get(status) ?? [];
            return (
              <div key={status} className="flex flex-col gap-2">
                <div className="flex items-center justify-between px-1">
                  <h2 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    {t(`leads.column.${status}` as TranslationKey)}
                  </h2>
                  <span className="text-xs text-zinc-400">{columnLeads.length}</span>
                </div>
                <div className="flex flex-col gap-2">
                  {columnLeads.length === 0 ? (
                    <EmptyState message="—" />
                  ) : (
                    columnLeads.map((lead) => (
                      <LeadCard
                        key={lead.id}
                        lead={lead}
                        onStatusChange={handleStatusChange}
                        onConvert={setConvertTarget}
                        changingStatus={changingId === String(lead.id)}
                      />
                    ))
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {convertTarget ? (
        <ConvertLeadModal lead={convertTarget} onClose={() => setConvertTarget(null)} onConverted={handleConverted} t={t} />
      ) : null}
    </div>
  );
}

function CreateLeadForm({
  onCreated,
  onCancel,
  t,
}: {
  onCreated: (created: Lead) => void;
  onCancel: () => void;
  t: (key: TranslationKey) => string;
}) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [source, setSource] = useState("");
  const [estimatedBudget, setEstimatedBudget] = useState("");
  const [notes, setNotes] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const created = await createLead({
        name,
        phone: phone || undefined,
        email: email || undefined,
        source: source || undefined,
        estimated_budget: estimatedBudget || undefined,
        notes: notes || undefined,
      });
      onCreated(created);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-3">
      <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("leads.create.title")}</p>
      <div className="flex flex-wrap items-end gap-3">
        <Field label={t("leads.create.name")} htmlFor="lead-name">
          <input
            id="lead-name"
            type="text"
            required
            value={name}
            onChange={(e) => setName(e.target.value)}
            className={`${INPUT_CLASSES} w-56`}
          />
        </Field>
        <Field label={t("leads.create.phone")} htmlFor="lead-phone">
          <input
            id="lead-phone"
            type="tel"
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            className={`${INPUT_CLASSES} w-40`}
          />
        </Field>
        <Field label={t("leads.create.email")} htmlFor="lead-email">
          <input
            id="lead-email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className={`${INPUT_CLASSES} w-56`}
          />
        </Field>
        <Field label={t("leads.create.source")} htmlFor="lead-source">
          <input
            id="lead-source"
            type="text"
            value={source}
            onChange={(e) => setSource(e.target.value)}
            className={`${INPUT_CLASSES} w-36`}
          />
        </Field>
        <Field label={t("leads.create.budget")} htmlFor="lead-budget">
          <input
            id="lead-budget"
            type="number"
            min="0"
            step="0.01"
            value={estimatedBudget}
            onChange={(e) => setEstimatedBudget(e.target.value)}
            className={`${INPUT_CLASSES} w-36`}
          />
        </Field>
      </div>
      <Field label={t("leads.create.notes")} htmlFor="lead-notes">
        <textarea
          id="lead-notes"
          rows={2}
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          className={`${INPUT_CLASSES} w-full resize-y`}
        />
      </Field>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={submitting}>
          {submitting ? t("leads.create.submitting") : t("leads.create.submit")}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("common.cancel")}
        </Button>
      </div>
    </form>
  );
}

function ConvertLeadModal({
  lead,
  onClose,
  onConverted,
  t,
}: {
  lead: Lead;
  onClose: () => void;
  onConverted: (lead: Lead) => void;
  t: (key: TranslationKey) => string;
}) {
  const [createProject, setCreateProject] = useState(false);
  const [projectName, setProjectName] = useState(`${lead.name} project`);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const result = await convertLead(lead.id, {
        createProject,
        projectName: createProject ? projectName : undefined,
      });
      onConverted(result.lead);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        className="w-full max-w-sm rounded-lg bg-white p-4 shadow-xl dark:bg-zinc-900"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
          {t("leads.convert.title")}
        </h2>
        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{lead.name}</p>

        <form onSubmit={handleSubmit} className="mt-4 flex flex-col gap-3">
          <label className="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
            <input
              type="checkbox"
              checked={createProject}
              onChange={(e) => setCreateProject(e.target.checked)}
            />
            {t("leads.convert.alsoCreateProject")}
          </label>

          {createProject ? (
            <Field label={t("leads.convert.projectName")} htmlFor="convert-project-name">
              <input
                id="convert-project-name"
                type="text"
                required
                value={projectName}
                onChange={(e) => setProjectName(e.target.value)}
                className={`${INPUT_CLASSES} w-full`}
              />
            </Field>
          ) : null}

          {error ? <ErrorBanner message={error} /> : null}

          <div className="mt-1 flex items-center gap-3">
            <Button type="submit" disabled={submitting}>
              {submitting ? t("leads.convert.submitting") : t("leads.convert.submit")}
            </Button>
            <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
              {t("common.cancel")}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={htmlFor} className="text-xs font-medium text-zinc-500 dark:text-zinc-400">
        {label}
      </label>
      {children}
    </div>
  );
}
