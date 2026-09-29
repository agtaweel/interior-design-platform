"use client";

/**
 * Execution tab — BRD S16 "Tasks/Site: Kanban/list, assignee, due date, photos" + S17 "Site
 * Report: work done, issues, decisions, photos, PDF". Two sections on one screen: a Tasks
 * Kanban board and a Site Reports list, both backed by the shared Spatie Media Library
 * infrastructure for photos (GET /execution-media/{id}/file — see lib/api/resources/
 * executionMedia.ts). Photo viewing reuses MediaLightbox (the same in-page viewer the
 * Documents tab/Media Gallery use) rather than downloading on click.
 */

import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useParams } from "next/navigation";
import { createTask, getTasks, updateTaskStatus } from "@/lib/api/resources/tasks";
import {
  createSiteReport,
  downloadSiteReportPdf,
  getSiteReports,
} from "@/lib/api/resources/siteReports";
import { fetchExecutionMediaObjectUrl } from "@/lib/api/resources/executionMedia";
import type { ExecutionPhoto, ProjectTask, SiteReport, TaskStatus } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { MediaLightbox } from "@/components/media/MediaLightbox";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { formatDate } from "@/lib/format/date";

type T = (key: TranslationKey) => string;
const TASK_STATUSES: TaskStatus[] = ["todo", "in_progress", "done"];
const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";

export default function ExecutionPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t } = useLocale();

  return (
    <div className="flex flex-col gap-8">
      <TasksSection projectId={projectId} t={t} />
      <SiteReportsSection projectId={projectId} t={t} />
    </div>
  );
}

function TasksSection({ projectId, t }: { projectId: string; t: T }) {
  const [tasks, setTasks] = useState<ProjectTask[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [lightboxPhoto, setLightboxPhoto] = useState<{ task: ProjectTask; photo: ExecutionPhoto } | null>(null);

  async function load() {
    setLoadError(null);
    try {
      setTasks(await getTasks(projectId));
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  const byStatus = useMemo(() => {
    const map = new Map<TaskStatus, ProjectTask[]>();
    for (const status of TASK_STATUSES) map.set(status, []);
    for (const task of tasks ?? []) map.get(task.status)?.push(task);
    return map;
  }, [tasks]);

  async function handleStatusChange(task: ProjectTask, status: TaskStatus) {
    try {
      const updated = await updateTaskStatus(task.id, status);
      setTasks((prev) => (prev ?? []).map((tk) => (String(tk.id) === String(task.id) ? updated : tk)));
    } catch {
      setLoadError(t("execution.tasks.statusFailed"));
    }
  }

  function handleCreated(created: ProjectTask) {
    setTasks((prev) => [...(prev ?? []), created]);
    setShowCreate(false);
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("execution.tasks.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("execution.tasks.subtitle")}</p>
        </div>
        <Button type="button" onClick={() => setShowCreate((v) => !v)}>
          {t("execution.tasks.create.cta")}
        </Button>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {showCreate ? (
        <Card>
          <CardBody>
            <CreateTaskForm projectId={projectId} onCreated={handleCreated} onCancel={() => setShowCreate(false)} t={t} />
          </CardBody>
        </Card>
      ) : null}

      {tasks === null ? (
        <LoadingScreen label={t("common.loading")} />
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {TASK_STATUSES.map((status) => (
            <div key={status} className="flex flex-col gap-2">
              <div className="flex items-center justify-between px-1">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                  {t(`execution.tasks.status.${status}` as TranslationKey)}
                </h2>
                <span className="text-xs text-zinc-400">{byStatus.get(status)?.length ?? 0}</span>
              </div>
              <div className="flex flex-col gap-2">
                {(byStatus.get(status) ?? []).length === 0 ? (
                  <EmptyState message="—" />
                ) : (
                  (byStatus.get(status) ?? []).map((task) => (
                    <TaskCard
                      key={task.id}
                      task={task}
                      onStatusChange={handleStatusChange}
                      onPhotoClick={(photo) => setLightboxPhoto({ task, photo })}
                      t={t}
                    />
                  ))
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {lightboxPhoto ? (
        <PhotoLightbox photo={lightboxPhoto.photo} onClose={() => setLightboxPhoto(null)} t={t} />
      ) : null}
    </div>
  );
}

function TaskCard({
  task,
  onStatusChange,
  onPhotoClick,
  t,
}: {
  task: ProjectTask;
  onStatusChange: (task: ProjectTask, status: TaskStatus) => void;
  onPhotoClick: (photo: ExecutionPhoto) => void;
  t: T;
}) {
  return (
    <Card className="flex flex-col gap-2 p-3">
      <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{task.title}</p>
      {task.description ? <p className="text-xs text-zinc-500 dark:text-zinc-400">{task.description}</p> : null}
      <div className="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
        <span>{task.assignee?.name ?? t("common.na")}</span>
        {task.due_date ? <span>{formatDate(task.due_date)}</span> : null}
      </div>
      {task.photos.length > 0 ? (
        <div className="flex flex-wrap gap-1">
          {task.photos.map((photo) => (
            <PhotoThumb key={photo.id} photo={photo} onClick={() => onPhotoClick(photo)} />
          ))}
        </div>
      ) : null}
      <select
        value={task.status}
        onChange={(e) => onStatusChange(task, e.target.value as TaskStatus)}
        className="w-full rounded border border-zinc-300 bg-white px-1.5 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-950"
      >
        {TASK_STATUSES.map((status) => (
          <option key={status} value={status}>
            {t(`execution.tasks.status.${status}` as TranslationKey)}
          </option>
        ))}
      </select>
    </Card>
  );
}

function PhotoThumb({ photo, onClick }: { photo: ExecutionPhoto; onClick: () => void }) {
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    let created: string | null = null;
    if (photo.mime_type.startsWith("image/")) {
      fetchExecutionMediaObjectUrl(photo.id)
        .then((u) => {
          if (cancelled) {
            URL.revokeObjectURL(u);
            return;
          }
          created = u;
          setUrl(u);
        })
        .catch(() => {});
    }
    return () => {
      cancelled = true;
      if (created) URL.revokeObjectURL(created);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [photo.id, photo.mime_type]);

  return (
    <button
      type="button"
      onClick={onClick}
      className="h-10 w-10 overflow-hidden rounded border border-zinc-200 bg-zinc-100 dark:border-zinc-800 dark:bg-zinc-800"
    >
      {url ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={url} alt="" className="h-full w-full object-cover" />
      ) : null}
    </button>
  );
}

function PhotoLightbox({ photo, onClose, t }: { photo: ExecutionPhoto; onClose: () => void; t: T }) {
  const [url, setUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetchExecutionMediaObjectUrl(photo.id).then((u) => {
      if (cancelled) {
        URL.revokeObjectURL(u);
        return;
      }
      setUrl(u);
    });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [photo.id]);

  return (
    <MediaLightbox
      fileName={photo.file_name}
      mimeType={photo.mime_type}
      objectUrl={url}
      caption={null}
      onClose={onClose}
      onDownload={() => {}}
      t={t}
    />
  );
}

function CreateTaskForm({
  projectId,
  onCreated,
  onCancel,
  t,
}: {
  projectId: string;
  onCreated: (created: ProjectTask) => void;
  onCancel: () => void;
  t: T;
}) {
  const [title, setTitle] = useState("");
  const [dueDate, setDueDate] = useState("");
  const [photos, setPhotos] = useState<File[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const created = await createTask(projectId, { title, due_date: dueDate || undefined, photos });
      onCreated(created);
    } catch (err) {
      setError(err instanceof Error ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-3">
      <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("execution.tasks.create.title")}</p>
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("execution.tasks.create.name")}</label>
          <input required value={title} onChange={(e) => setTitle(e.target.value)} className={`${INPUT_CLASSES} w-56`} />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("execution.tasks.create.dueDate")}</label>
          <input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} className={INPUT_CLASSES} />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("execution.tasks.create.photos")}</label>
          <input
            type="file"
            multiple
            accept="image/jpeg,image/png,image/webp"
            onChange={(e) => setPhotos(Array.from(e.target.files ?? []))}
            className="text-sm text-zinc-600 dark:text-zinc-300"
          />
        </div>
      </div>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={submitting}>
          {submitting ? t("execution.tasks.create.submitting") : t("execution.tasks.create.submit")}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("common.cancel")}
        </Button>
      </div>
    </form>
  );
}

function SiteReportsSection({ projectId, t }: { projectId: string; t: T }) {
  const [reports, setReports] = useState<SiteReport[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);

  async function load() {
    setLoadError(null);
    try {
      setReports(await getSiteReports(projectId));
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  function handleCreated(created: SiteReport) {
    setReports((prev) => [created, ...(prev ?? [])]);
    setShowCreate(false);
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">{t("execution.reports.title")}</h2>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("execution.reports.subtitle")}</p>
        </div>
        <Button type="button" onClick={() => setShowCreate((v) => !v)}>
          {t("execution.reports.create.cta")}
        </Button>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {showCreate ? (
        <Card>
          <CardBody>
            <CreateSiteReportForm projectId={projectId} onCreated={handleCreated} onCancel={() => setShowCreate(false)} t={t} />
          </CardBody>
        </Card>
      ) : null}

      {reports === null ? (
        <LoadingScreen label={t("common.loading")} />
      ) : reports.length === 0 ? (
        <EmptyState message={t("execution.reports.empty")} />
      ) : (
        <div className="flex flex-col gap-3">
          {reports.map((report) => (
            <SiteReportCard key={report.id} report={report} t={t} />
          ))}
        </div>
      )}
    </div>
  );
}

function SiteReportCard({ report, t }: { report: SiteReport; t: T }) {
  const [downloading, setDownloading] = useState(false);

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadSiteReportPdf(report.id);
    } finally {
      setDownloading(false);
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-zinc-900 dark:text-zinc-100">{formatDate(report.report_date)}</p>
          <p className="text-xs text-zinc-500 dark:text-zinc-400">{report.reported_by.name}</p>
        </div>
        <button
          type="button"
          onClick={handleDownload}
          disabled={downloading}
          className="text-xs font-medium text-zinc-500 underline underline-offset-2 hover:text-zinc-900 disabled:opacity-60 dark:hover:text-zinc-100"
        >
          {downloading ? t("execution.reports.downloading") : t("execution.reports.downloadPdf")}
        </button>
      </CardHeader>
      <CardBody className="flex flex-col gap-2 text-sm">
        <p className="text-zinc-700 dark:text-zinc-200">{report.work_done}</p>
        {report.issues ? (
          <p className="text-amber-700 dark:text-amber-400">
            <strong>{t("execution.reports.issues")}:</strong> {report.issues}
          </p>
        ) : null}
        {report.decisions ? (
          <p className="text-zinc-600 dark:text-zinc-300">
            <strong>{t("execution.reports.decisions")}:</strong> {report.decisions}
          </p>
        ) : null}
        {report.photos.length > 0 ? (
          <p className="text-xs text-zinc-400">{report.photos.length} {t("execution.reports.photosCount")}</p>
        ) : null}
      </CardBody>
    </Card>
  );
}

function CreateSiteReportForm({
  projectId,
  onCreated,
  onCancel,
  t,
}: {
  projectId: string;
  onCreated: (created: SiteReport) => void;
  onCancel: () => void;
  t: T;
}) {
  const [reportDate, setReportDate] = useState(new Date().toISOString().slice(0, 10));
  const [workDone, setWorkDone] = useState("");
  const [issues, setIssues] = useState("");
  const [decisions, setDecisions] = useState("");
  const [photos, setPhotos] = useState<File[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const created = await createSiteReport(projectId, {
        report_date: reportDate,
        work_done: workDone,
        issues: issues || undefined,
        decisions: decisions || undefined,
        photos,
      });
      onCreated(created);
    } catch (err) {
      setError(err instanceof Error ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-3">
      <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("execution.reports.create.title")}</p>
      <div className="flex flex-col gap-1">
        <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("execution.reports.create.date")}</label>
        <input type="date" required value={reportDate} onChange={(e) => setReportDate(e.target.value)} className={`${INPUT_CLASSES} w-48`} />
      </div>
      <div className="flex flex-col gap-1">
        <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("execution.reports.create.workDone")}</label>
        <textarea required rows={2} value={workDone} onChange={(e) => setWorkDone(e.target.value)} className={`${INPUT_CLASSES} resize-y`} />
      </div>
      <div className="flex flex-col gap-1">
        <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("execution.reports.create.issues")}</label>
        <textarea rows={2} value={issues} onChange={(e) => setIssues(e.target.value)} className={`${INPUT_CLASSES} resize-y`} />
      </div>
      <div className="flex flex-col gap-1">
        <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("execution.reports.create.decisions")}</label>
        <textarea rows={2} value={decisions} onChange={(e) => setDecisions(e.target.value)} className={`${INPUT_CLASSES} resize-y`} />
      </div>
      <div className="flex flex-col gap-1">
        <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("execution.tasks.create.photos")}</label>
        <input
          type="file"
          multiple
          accept="image/jpeg,image/png,image/webp"
          onChange={(e) => setPhotos(Array.from(e.target.files ?? []))}
          className="text-sm text-zinc-600 dark:text-zinc-300"
        />
      </div>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={submitting}>
          {submitting ? t("execution.reports.create.submitting") : t("execution.reports.create.submit")}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("common.cancel")}
        </Button>
      </div>
    </form>
  );
}
