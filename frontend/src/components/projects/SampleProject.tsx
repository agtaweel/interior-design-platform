"use client";

/**
 * docs/SIMPLIFICATION_REVIEW.md P4.3 — the one-click sample project.
 *  - <SampleProjectActions>: "Explore a sample project" (creates it in the current language and
 *    opens it), or Open / Delete once it exists. Used in the dashboard checklist and on /help.
 *  - <SampleProjectBanner>: shown on every tab of the sample project so nobody mistakes it for
 *    a real job, with the same one-click delete.
 * Creating/deleting needs manage_projects; for other roles the backend 403s and the button just
 * reports the error.
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { createSampleProject, deleteSampleProject, getSampleProject } from "@/lib/api/resources/sampleProject";
import { useLocale } from "@/lib/i18n/LocaleProvider";

const LINK_BUTTON =
  "inline-flex h-9 items-center rounded-md border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-900 hover:border-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100";

export function SampleProjectActions() {
  const { t, locale } = useLocale();
  const router = useRouter();
  // undefined = loading; null = none yet.
  const [sampleId, setSampleId] = useState<string | null | undefined>(undefined);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getSampleProject()
      .then((ref) => setSampleId(ref ? String(ref.project_id) : null))
      .catch(() => setSampleId(null));
  }, []);

  async function handleCreate() {
    setBusy(true);
    setError(null);
    try {
      const ref = await createSampleProject(locale);
      router.push(`/projects/${ref.project_id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
      setBusy(false);
    }
  }

  async function handleDelete() {
    if (!window.confirm(t("sample.deleteConfirm"))) return;
    setBusy(true);
    try {
      await deleteSampleProject();
      setSampleId(null);
    } finally {
      setBusy(false);
    }
  }

  if (sampleId === undefined) return null;

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
      {sampleId ? (
        <>
          <Link href={`/projects/${sampleId}`} className={LINK_BUTTON}>
            {t("sample.open")}
          </Link>
          <button type="button" onClick={handleDelete} disabled={busy} className="text-zinc-500 underline underline-offset-2 hover:text-red-700 dark:text-zinc-400">
            {t("sample.delete")}
          </button>
        </>
      ) : (
        <>
          <button type="button" onClick={handleCreate} disabled={busy} className={LINK_BUTTON}>
            {busy ? t("sample.creating") : t("sample.cta")}
          </button>
          <span className="text-zinc-500 dark:text-zinc-400">{t("sample.hint")}</span>
        </>
      )}
      {error ? <p role="alert" className="w-full text-red-700 dark:text-red-400">{error}</p> : null}
    </div>
  );
}

export function SampleProjectBanner() {
  const { t } = useLocale();
  const router = useRouter();
  const [busy, setBusy] = useState(false);

  async function handleDelete() {
    if (!window.confirm(t("sample.deleteConfirm"))) return;
    setBusy(true);
    try {
      await deleteSampleProject();
      router.push("/dashboard");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div role="note" className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-200">
      <span>
        <span className="me-2 rounded bg-sky-200 px-1.5 py-0.5 text-xs font-semibold uppercase dark:bg-sky-900">{t("sample.badge")}</span>
        {t("sample.banner")}
      </span>
      <button type="button" onClick={handleDelete} disabled={busy} className="font-medium underline underline-offset-2 hover:no-underline">
        {t("sample.delete")}
      </button>
    </div>
  );
}
