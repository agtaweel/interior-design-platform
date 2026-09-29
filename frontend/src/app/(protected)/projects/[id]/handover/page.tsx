"use client";

/**
 * Handover tab — BRD S20 "Final approval, warranty, completion document." One per project;
 * creating it is blocked server-side while any mandatory snag is still open (BRD's explicit
 * "project cannot be marked complete with unresolved mandatory snags" rule) — the 409 from
 * that check is surfaced verbatim rather than a generic error, since it tells the user exactly
 * what to go fix (close the remaining snags in the Snagging tab).
 */

import { useEffect, useState, type FormEvent } from "react";
import { useParams } from "next/navigation";
import { createHandover, downloadHandoverPdf, getHandover } from "@/lib/api/resources/handover";
import type { Handover } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { formatDate } from "@/lib/format/date";

type T = (key: TranslationKey) => string;
const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";

export default function HandoverPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t } = useLocale();

  const [handover, setHandover] = useState<Handover | null | undefined>(undefined);
  const [loadError, setLoadError] = useState<string | null>(null);

  async function load() {
    setLoadError(null);
    try {
      setHandover(await getHandover(projectId));
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  if (handover === undefined && !loadError) {
    return <LoadingScreen label={t("common.loading")} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("handover.title")}</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("handover.subtitle")}</p>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {handover ? (
        <HandoverSummary handover={handover} projectId={projectId} t={t} />
      ) : (
        <CreateHandoverForm projectId={projectId} onCreated={setHandover} t={t} />
      )}
    </div>
  );
}

function HandoverSummary({ handover, projectId, t }: { handover: Handover; projectId: string; t: T }) {
  const [downloading, setDownloading] = useState(false);

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadHandoverPdf(projectId);
    } finally {
      setDownloading(false);
    }
  }

  return (
    <Card>
      <CardBody className="flex flex-col gap-3">
        <p className="text-sm font-medium text-green-700 dark:text-green-400">{t("handover.completed")}</p>
        <dl className="grid grid-cols-2 gap-3 text-sm">
          <div>
            <dt className="text-xs uppercase tracking-wide text-zinc-400">{t("handover.date")}</dt>
            <dd className="text-zinc-900 dark:text-zinc-100">{formatDate(handover.handover_date)}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-zinc-400">{t("handover.approvedBy")}</dt>
            <dd className="text-zinc-900 dark:text-zinc-100">{handover.approved_by.name}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-zinc-400">{t("handover.warrantyPeriod")}</dt>
            <dd className="text-zinc-900 dark:text-zinc-100">
              {handover.warranty_period_months !== null ? `${handover.warranty_period_months} ${t("handover.months")}` : t("common.na")}
            </dd>
          </div>
        </dl>
        {handover.warranty_notes ? (
          <p className="text-sm text-zinc-600 dark:text-zinc-300">
            <strong>{t("handover.warrantyNotes")}:</strong> {handover.warranty_notes}
          </p>
        ) : null}
        <div>
          <Button type="button" onClick={handleDownload} disabled={downloading}>
            {downloading ? t("handover.downloading") : t("handover.downloadCertificate")}
          </Button>
        </div>
      </CardBody>
    </Card>
  );
}

function CreateHandoverForm({
  projectId,
  onCreated,
  t,
}: {
  projectId: string;
  onCreated: (created: Handover) => void;
  t: T;
}) {
  const [handoverDate, setHandoverDate] = useState(new Date().toISOString().slice(0, 10));
  const [warrantyMonths, setWarrantyMonths] = useState("12");
  const [warrantyNotes, setWarrantyNotes] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const created = await createHandover(projectId, {
        handover_date: handoverDate,
        warranty_period_months: warrantyMonths ? Number(warrantyMonths) : undefined,
        warranty_notes: warrantyNotes || undefined,
      });
      onCreated(created);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card>
      <CardBody>
        <form onSubmit={handleSubmit} className="flex flex-col gap-3">
          <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("handover.create.title")}</p>
          <div className="flex flex-wrap items-end gap-3">
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("handover.create.date")}</label>
              <input type="date" required value={handoverDate} onChange={(e) => setHandoverDate(e.target.value)} className={INPUT_CLASSES} />
            </div>
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("handover.create.warrantyMonths")}</label>
              <input type="number" min="0" value={warrantyMonths} onChange={(e) => setWarrantyMonths(e.target.value)} className={`${INPUT_CLASSES} w-28`} />
            </div>
          </div>
          <div className="flex flex-col gap-1">
            <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("handover.create.warrantyNotes")}</label>
            <textarea rows={2} value={warrantyNotes} onChange={(e) => setWarrantyNotes(e.target.value)} className={`${INPUT_CLASSES} resize-y`} />
          </div>

          {error ? <ErrorBanner message={error} /> : null}

          <div>
            <Button type="submit" disabled={submitting}>
              {submitting ? t("handover.create.submitting") : t("handover.create.submit")}
            </Button>
          </div>
        </form>
      </CardBody>
    </Card>
  );
}
