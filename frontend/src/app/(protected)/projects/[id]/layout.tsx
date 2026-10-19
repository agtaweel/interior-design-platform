"use client";

/**
 * In-project navigation, grouped by the stage of the job (docs/SIMPLIFICATION_REVIEW.md P2.3):
 *   Overview · Quote (Items & Prices, Proposal) · Agreement (Contract, Payments)
 *   · Work (Site Work, Expenses, Extras & Changes) · Finish (Fix List, Handover) · Documents
 * The stage the project is in is marked "you are here"; later stages are dimmed but still
 * clickable — real jobs do sometimes order materials or log a site visit before signing, so this
 * guides rather than blocks. Stage + next step come from GET /projects/{id}/journey (shared with
 * the overview's "Next step" card through ProjectJourneyProvider).
 *
 * Activity (an audit-log viewer) is not shown until it exists; the org-wide audit log lives under
 * Settings.
 */

import Link from "next/link";
import { usePathname, useParams } from "next/navigation";
import type { ReactNode } from "react";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import {
  ProjectJourneyProvider,
  STAGE_ORDER,
  useProjectJourney,
  type ProjectStage,
} from "@/lib/projects/journey";

interface Tab {
  label: TranslationKey;
  /** Route segment under /projects/{id}; "" is the overview. */
  segment: string;
}

interface Group {
  /** null = not part of the stage flow (Overview, Documents). */
  stage: Exclude<ProjectStage, "done"> | null;
  tabs: Tab[];
}

const GROUPS: Group[] = [
  { stage: null, tabs: [{ label: "project.tabs.overview", segment: "" }] },
  {
    stage: "quote",
    tabs: [
      { label: "project.tabs.boq", segment: "boq" },
      { label: "project.tabs.proposal", segment: "proposal" },
    ],
  },
  {
    stage: "agreement",
    tabs: [
      { label: "project.tabs.contract", segment: "contract" },
      { label: "project.tabs.payments", segment: "payments" },
    ],
  },
  {
    stage: "work",
    tabs: [
      { label: "project.tabs.execution", segment: "execution" },
      { label: "project.tabs.expenses", segment: "expenses" },
      { label: "project.tabs.changeOrders", segment: "change-orders" },
    ],
  },
  {
    stage: "finish",
    tabs: [
      { label: "project.tabs.snagging", segment: "snagging" },
      { label: "project.tabs.handover", segment: "handover" },
    ],
  },
  { stage: null, tabs: [{ label: "project.tabs.documents", segment: "documents" }] },
];

function ProjectTabs({ base }: { base: string }) {
  const pathname = usePathname();
  const { t } = useLocale();
  const journey = useProjectJourney();
  const currentIndex = journey ? STAGE_ORDER.indexOf(journey.stage) : -1;

  return (
    <nav className="-mx-4 overflow-x-auto px-4">
      <div className="flex min-w-max items-end gap-x-4 border-b border-zinc-200 dark:border-zinc-800">
        {GROUPS.map((group, gi) => {
          const groupIndex = group.stage ? STAGE_ORDER.indexOf(group.stage) : -1;
          const isCurrent = journey !== null && group.stage === journey.stage;
          const isLater = journey !== null && group.stage !== null && groupIndex > currentIndex;

          return (
            <div key={gi} className="flex flex-col">
              <span
                className={`flex h-5 items-center gap-1 px-3 text-[11px] font-medium uppercase tracking-wide ${
                  isCurrent ? "text-amber-700 dark:text-amber-400" : "text-zinc-400 dark:text-zinc-500"
                }`}
              >
                {group.stage ? (
                  <>
                    {isCurrent ? <span aria-hidden="true">●</span> : null}
                    {t(`project.stage.${group.stage}`)}
                    {isCurrent ? <span className="sr-only">({t("project.stage.youAreHere")})</span> : null}
                  </>
                ) : null}
              </span>
              <div className="flex">
                {group.tabs.map((tab) => {
                  const href = tab.segment === "" ? base : `${base}/${tab.segment}`;
                  const active = tab.segment === "" ? pathname === base : pathname.startsWith(href);
                  return (
                    <Link
                      key={tab.segment}
                      href={href}
                      aria-current={active ? "page" : undefined}
                      title={isLater ? t("project.stage.laterHint") : undefined}
                      className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                        active
                          ? "border-zinc-900 text-zinc-900 dark:border-zinc-100 dark:text-zinc-50"
                          : isLater
                            ? "border-transparent text-zinc-400 hover:text-zinc-700 dark:text-zinc-600 dark:hover:text-zinc-300"
                            : "border-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                      }`}
                    >
                      {t(tab.label)}
                    </Link>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>
    </nav>
  );
}

export default function ProjectLayout({ children }: { children: ReactNode }) {
  const params = useParams<{ id: string }>();
  const pathname = usePathname();
  const base = `/projects/${params.id}`;

  return (
    <ProjectJourneyProvider projectId={params.id} refreshKey={pathname}>
      <div className="flex flex-col gap-6">
        <ProjectTabs base={base} />
        {children}
      </div>
    </ProjectJourneyProvider>
  );
}
