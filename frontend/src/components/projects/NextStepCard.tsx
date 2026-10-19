"use client";

/**
 * Project overview's "Next step": one sentence on where the job is and one button to the tab
 * where the next action happens. Driven by the shared journey (lib/projects/journey.tsx), so it
 * always agrees with the stage marked in the tab bar. Renders nothing until the journey loads.
 */

import Link from "next/link";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { useProjectJourney } from "@/lib/projects/journey";

export function NextStepCard({ projectId }: { projectId: string | number }) {
  const { t } = useLocale();
  const journey = useProjectJourney();
  if (!journey) return null;

  const { key, tab } = journey.next_step;
  const k = (part: "title" | "body" | "cta") => t(`project.next.${key}.${part}` as TranslationKey);
  const href = tab ? `/projects/${projectId}/${tab}` : `/projects/${projectId}`;
  const done = key === "handed_over";

  return (
    <section
      aria-labelledby="next-step-title"
      className={`flex flex-wrap items-center justify-between gap-4 rounded-lg border p-5 ${
        done
          ? "border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40"
          : "border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30"
      }`}
    >
      <div className="min-w-0">
        <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
          {t("project.next.label")} · {t(`project.stage.${journey.stage}`)}
        </p>
        <h2 id="next-step-title" className="mt-1 text-lg font-semibold text-zinc-900 dark:text-zinc-50">
          {k("title")}
        </h2>
        <p className="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">{k("body")}</p>
      </div>
      <Link
        href={href}
        className="inline-flex h-10 shrink-0 items-center rounded-md bg-zinc-950 px-4 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200"
      >
        {k("cta")}
      </Link>
    </section>
  );
}
