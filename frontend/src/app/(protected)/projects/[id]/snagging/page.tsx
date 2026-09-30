"use client";

/**
 * Snagging tab — BRD S19 "Track defect to closure": defects, priorities, owners, due dates,
 * closure, warranty. A mandatory open snag here is what blocks a project from being marked
 * completed or handed over (see backend Snag::hasOpenMandatorySnags()).
 */

import { useEffect, useState, type FormEvent } from "react";
import { useParams } from "next/navigation";
import { closeSnag, createSnag, getSnags, reopenSnag } from "@/lib/api/resources/snags";
import type { Snag, SnagPriority } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { formatDate } from "@/lib/format/date";

type T = (key: TranslationKey) => string;
const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

function priorityTone(priority: SnagPriority): "green" | "red" | "amber" {
  if (priority === "critical" || priority === "high") return "red";
  if (priority === "medium") return "amber";
  return "green";
}

export default function SnaggingPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t } = useLocale();

  const [snags, setSnags] = useState<Snag[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [filter, setFilter] = useState<"open" | "closed">("open");

  async function load() {
    setLoadError(null);
    try {
      setSnags(await getSnags(projectId));
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  function handleCreated(created: Snag) {
    setSnags((prev) => [created, ...(prev ?? [])]);
    setShowCreate(false);
  }

  function handleUpdated(updated: Snag) {
    setSnags((prev) => (prev ?? []).map((s) => (String(s.id) === String(updated.id) ? updated : s)));
  }

  const visible = (snags ?? []).filter((s) => s.status === filter);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("snagging.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("snagging.subtitle")}</p>
        </div>
        <Button type="button" onClick={() => setShowCreate((v) => !v)}>
          {t("snagging.create.cta")}
        </Button>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {showCreate ? (
        <Card>
          <CardBody>
            <CreateSnagForm projectId={projectId} onCreated={handleCreated} onCancel={() => setShowCreate(false)} t={t} />
          </CardBody>
        </Card>
      ) : null}

      <div className="inline-flex w-fit rounded-md border border-zinc-300 p-0.5 text-sm dark:border-zinc-700">
        {(["open", "closed"] as const).map((option) => (
          <button
            key={option}
            type="button"
            onClick={() => setFilter(option)}
            className={`rounded px-3 py-1 font-medium transition-colors ${
              filter === option
                ? "bg-amber-600 text-white dark:bg-amber-500 dark:text-zinc-950"
                : "text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            }`}
          >
            {t(`snagging.filter.${option}` as TranslationKey)} ({(snags ?? []).filter((s) => s.status === option).length})
          </button>
        ))}
      </div>

      {snags === null ? (
        <LoadingScreen label={t("common.loading")} />
      ) : visible.length === 0 ? (
        <EmptyState message={t("snagging.empty")} />
      ) : (
        <div className="flex flex-col gap-3">
          {visible.map((snag) => (
            <SnagCard key={snag.id} snag={snag} onUpdated={handleUpdated} t={t} />
          ))}
        </div>
      )}
    </div>
  );
}

function SnagCard({ snag, onUpdated, t }: { snag: Snag; onUpdated: (updated: Snag) => void; t: T }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notes, setNotes] = useState("");

  async function handleClose() {
    setBusy(true);
    setError(null);
    try {
      onUpdated(await closeSnag(snag.id, notes || undefined));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setBusy(false);
    }
  }

  async function handleReopen() {
    setBusy(true);
    setError(null);
    try {
      onUpdated(await reopenSnag(snag.id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-zinc-900 dark:text-zinc-100">{snag.description}</p>
          <p className="text-xs text-zinc-500 dark:text-zinc-400">
            {snag.owner?.name ?? t("common.na")}
            {snag.due_date ? ` · ${formatDate(snag.due_date)}` : ""}
          </p>
        </div>
        <div className="flex items-center gap-2">
          {snag.is_mandatory ? <Badge tone="red">{t("snagging.mandatory")}</Badge> : null}
          <Badge tone={priorityTone(snag.priority)}>{t(`snagging.priority.${snag.priority}` as TranslationKey)}</Badge>
        </div>
      </CardHeader>
      <CardBody className="flex flex-col gap-2">
        {snag.status === "closed" ? (
          <>
            {snag.resolution_notes ? <p className="text-sm text-zinc-600 dark:text-zinc-300">{snag.resolution_notes}</p> : null}
            <p className="text-xs text-green-700 dark:text-green-400">
              {t("snagging.closedOn")} {snag.closed_at ? formatDate(snag.closed_at) : ""}
            </p>
            <div>
              <Button type="button" variant="secondary" className="py-1" onClick={handleReopen} disabled={busy}>
                {t("snagging.action.reopen")}
              </Button>
            </div>
          </>
        ) : (
          <div className="flex flex-wrap items-end gap-3">
            <input
              placeholder={t("snagging.resolutionNotesPlaceholder")}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              className={`${INPUT_CLASSES} flex-1`}
            />
            <Button type="button" className="py-1" onClick={handleClose} disabled={busy}>
              {t("snagging.action.close")}
            </Button>
          </div>
        )}
        {error ? <ErrorBanner message={error} /> : null}
      </CardBody>
    </Card>
  );
}

function CreateSnagForm({
  projectId,
  onCreated,
  onCancel,
  t,
}: {
  projectId: string;
  onCreated: (created: Snag) => void;
  onCancel: () => void;
  t: T;
}) {
  const [description, setDescription] = useState("");
  const [priority, setPriority] = useState<SnagPriority>("medium");
  const [dueDate, setDueDate] = useState("");
  const [isMandatory, setIsMandatory] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const created = await createSnag(projectId, {
        description,
        priority,
        due_date: dueDate || undefined,
        is_mandatory: isMandatory,
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
      <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("snagging.create.title")}</p>
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("snagging.create.description")}</label>
          <input required value={description} onChange={(e) => setDescription(e.target.value)} className={`${INPUT_CLASSES} w-64`} />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("snagging.create.priority")}</label>
          <select value={priority} onChange={(e) => setPriority(e.target.value as SnagPriority)} className={INPUT_CLASSES}>
            <option value="low">{t("snagging.priority.low")}</option>
            <option value="medium">{t("snagging.priority.medium")}</option>
            <option value="high">{t("snagging.priority.high")}</option>
            <option value="critical">{t("snagging.priority.critical")}</option>
          </select>
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("snagging.create.dueDate")}</label>
          <input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} className={INPUT_CLASSES} />
        </div>
        <label className="flex items-center gap-2 pb-2 text-sm text-zinc-700 dark:text-zinc-200">
          <input type="checkbox" checked={isMandatory} onChange={(e) => setIsMandatory(e.target.checked)} />
          {t("snagging.create.mandatory")}
        </label>
      </div>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={submitting}>
          {submitting ? t("snagging.create.submitting") : t("snagging.create.submit")}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("common.cancel")}
        </Button>
      </div>
    </form>
  );
}
