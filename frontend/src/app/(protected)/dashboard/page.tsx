"use client";

/**
 * S02 — Main Dashboard. Answers one question first: which jobs need chasing, for money or for
 * time? The title row states the finding (projects past their target finish, money left to
 * collect); the biggest block is collection against contract per active project.
 *
 * Data:
 *  - GET /projects?status=active — collection rows + target-finish list (first page; meta.total
 *    for the count). GET /projects — recently added.
 *  - GET /reports/summary — the money KPIs. Requires Permissions::VIEW_FINANCIALS, which not
 *    every role has (e.g. Designer): a 403 there is expected, so `canSeeMoney` goes false and
 *    every money figure on the screen (KPIs and the collection columns) is withheld rather than
 *    the screen erroring.
 *  - GET /leads — the open-pipeline block; fails inside its own frame only.
 * "Overdue items" used to be a stub with no backing API; it is now derived honestly from
 * target_end_date on active/on-hold projects (see lib/dashboard/derive.ts).
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { listProjects } from "@/lib/api/resources/projects";
import { getReportSummary } from "@/lib/api/resources/reports";
import { getLeads } from "@/lib/api/resources/leads";
import type { Lead, Project, ReportSummary } from "@/lib/api/types";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { useMoneyFormatter } from "@/lib/format/useMoneyFormatter";
import { formatDate } from "@/lib/format/date";
import {
  cairoToday,
  collectionRows,
  finishSchedule,
  leadStages,
  type FinishRow,
} from "@/lib/dashboard/derive";
import {
  Bar,
  BlockError,
  EmptyLine,
  KpiStrip,
  Panel,
  StatusPill,
  Track,
} from "@/components/dashboard/Ledger";
import { GettingStarted } from "@/components/dashboard/GettingStarted";

const LIST_CAP = 5;

type Load<T> = { state: "loading" } | { state: "error"; message: string } | { state: "ready"; data: T };

export default function DashboardPage() {
  const { t, locale } = useLocale();
  const { formatMoney } = useMoneyFormatter();

  const [active, setActive] = useState<Load<{ rows: Project[]; total: number }>>({ state: "loading" });
  const [recent, setRecent] = useState<Load<{ rows: Project[]; total: number }>>({ state: "loading" });
  // undefined = still loading; null = no VIEW_FINANCIALS (or the request failed).
  const [summary, setSummary] = useState<ReportSummary | null | undefined>(undefined);
  const [leads, setLeads] = useState<Load<Lead[]>>({ state: "loading" });
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);

  const errorMessage = (err: unknown) => (err instanceof ApiError ? err.message : t("common.unknownError"));

  async function load() {
    await Promise.all([
      listProjects({ status: "active" })
        .then((r) => setActive({ state: "ready", data: { rows: r.data, total: r.meta.total } }))
        .catch((err) => setActive({ state: "error", message: errorMessage(err) })),
      listProjects()
        .then((r) => setRecent({ state: "ready", data: { rows: r.data, total: r.meta.total } }))
        .catch((err) => setRecent({ state: "error", message: errorMessage(err) })),
      getReportSummary()
        .then(setSummary)
        .catch(() => setSummary(null)),
      getLeads()
        .then((data) => setLeads({ state: "ready", data }))
        .catch((err) => setLeads({ state: "error", message: errorMessage(err) })),
    ]);
    setUpdatedAt(new Date());
  }

  // Intentional fetch-on-mount — the standard pattern for this app's data screens (see also the
  // clients/projects/properties pages). A refresh keeps the old values on screen until the new
  // ones land, so the layout never jumps back to its loading bars.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- load() only depends on stable imports
  }, []);

  const today = cairoToday();
  const canSeeMoney = summary !== null;
  const activeRows = active.state === "ready" ? active.data.rows : [];
  const finish = finishSchedule(activeRows, today);
  const recentRows = recent.state === "ready" ? recent.data.rows : [];
  const known = [...activeRows, ...recentRows.filter((p) => !activeRows.some((a) => a.id === p.id))];
  const collection = collectionRows(known);
  const collectionState = active.state === "error" ? active : recent.state === "error" ? recent : active.state === "loading" || recent.state === "loading" ? { state: "loading" as const } : { state: "ready" as const };
  const lateCount = finish.filter((row) => row.days < 0).length;

  // Counts use the locale's digits, the same as formatMoney does for amounts.
  const num = (n: number) => new Intl.NumberFormat(locale === "ar" ? "ar-EG" : "en-EG").format(n);
  const fill = (key: TranslationKey, values: Record<string, string | number>) =>
    Object.entries(values).reduce(
      (text, [k, v]) => text.replace(`{${k}}`, typeof v === "number" ? num(v) : v),
      t(key),
    );
  const statusLabel = (status: string) => {
    const key = `dashboard.status.${status}` as TranslationKey;
    const label = t(key);
    return label === key ? status.replace(/_/g, " ") : label;
  };

  // The finding: one sentence under the title, built from the same values the blocks show.
  const finding: string[] = [];
  if (active.state === "ready") {
    finding.push(
      lateCount === 0
        ? t("dashboard.finding.noneLate")
        : fill(lateCount === 1 ? "dashboard.finding.lateOne" : "dashboard.finding.lateMany", { count: lateCount }),
    );
  }
  if (summary) finding.push(fill("dashboard.finding.toCollect", { amount: formatMoney(summary.total_receivables) }));

  const money = (value: number | string | undefined) =>
    summary === undefined ? <Bar className="h-8 w-40" /> : canSeeMoney ? formatMoney(value ?? 0) : <Dash />;
  const count = (value: number | undefined) =>
    value === undefined ? <Bar className="h-8 w-12" /> : num(value);

  return (
    <div className="ledger flex flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3 pb-2">
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold tracking-[-0.01em]">{t("dashboard.title")}</h1>
          <p className="mt-1 text-sm text-[var(--l-muted)]">
            {finding.length ? finding.join(" · ") : <Bar className="mt-1 h-4 w-72" />}
          </p>
        </div>
        <div className="flex items-center gap-4">
          {updatedAt ? (
            <span className="hidden text-xs text-[var(--l-muted)] sm:inline">
              {fill("dashboard.updated", {
                time: new Intl.DateTimeFormat(locale === "ar" ? "ar-EG" : "en-EG", {
                  hour: "2-digit",
                  minute: "2-digit",
                  timeZone: "Africa/Cairo",
                }).format(updatedAt),
              })}
            </span>
          ) : null}
          <button
            type="button"
            onClick={load}
            className="h-9 rounded-md border border-[var(--l-border)] bg-[var(--l-surface)] px-3 text-sm font-medium hover:border-[var(--l-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)]"
          >
            {t("dashboard.refresh")}
          </button>
          <Link
            href="/projects?new=1"
            className="inline-flex h-9 items-center rounded-md bg-[var(--l-primary)] px-4 text-sm font-medium text-[var(--l-primary-ink)] hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)]"
          >
            {t("projects.new.cta")}
          </Link>
        </div>
      </div>

      <GettingStarted
        projects={recent.state === "ready" ? recent.data.rows : undefined}
        projectTotal={recent.state === "ready" ? recent.data.total : undefined}
        summary={summary}
      />

      <KpiStrip
        cells={[
          {
            label: t("dashboard.kpi.receivables"),
            value: money(summary?.total_receivables),
            note: canSeeMoney ? t("dashboard.kpi.receivablesNote") : t("dashboard.kpi.noAccess"),
            lead: canSeeMoney,
          },
          {
            label: t("dashboard.kpi.monthlyRevenue"),
            value: money(summary?.monthly_revenue),
            note: canSeeMoney
              ? new Intl.DateTimeFormat(locale === "ar" ? "ar-EG" : "en-EG", {
                  month: "long",
                  year: "numeric",
                  timeZone: "Africa/Cairo",
                }).format(new Date())
              : t("dashboard.kpi.noAccess"),
          },
          {
            label: t("dashboard.kpi.pendingProposals"),
            value: summary === null ? <Dash /> : count(summary?.pending_proposals_count),
            note: t("dashboard.kpi.pendingProposalsNote"),
          },
          {
            label: t("dashboard.kpi.activeProjects"),
            value: active.state === "error" ? <Dash /> : count(active.state === "ready" ? active.data.total : undefined),
            note:
              active.state === "ready"
                ? lateCount === 0
                  ? t("dashboard.kpi.noneLate")
                  : fill("dashboard.kpi.late", { count: lateCount })
                : undefined,
          },
        ]}
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        {/* 12 columns, 16px gutter: 8 + 4 per row, so both rows land on the canvas edges. */}
        <Panel
          className="lg:col-span-8"
          title={t("dashboard.collection.title")}
          meta={canSeeMoney ? t("dashboard.collection.meta") : t("dashboard.collection.metaNoMoney")}
          action={{ href: "/projects", label: t("dashboard.viewAll") }}
        >
          {collectionState.state === "loading" ? (
            <SkeletonRows rows={4} />
          ) : collectionState.state === "error" ? (
            <BlockError message={collectionState.message} onRetry={load} retryLabel={t("common.retry")} />
          ) : collection.length === 0 ? (
            <EmptyLine>{t("dashboard.collection.empty")}</EmptyLine>
          ) : (
            <ul className="-mx-2">
              {collection.slice(0, LIST_CAP).map((row) => (
                <li key={row.project.id} className="border-t border-[var(--l-rule)] first:border-t-0">
                  <Link
                    href={`/projects/${row.project.id}`}
                    className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-2 rounded-md px-2 py-3 outline-1 -outline-offset-1 outline-transparent hover:outline-[var(--l-border)] focus-visible:outline-2 focus-visible:outline-[var(--l-text)] sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_8.5rem]"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{row.project.name}</p>
                      <p className="truncate text-xs text-[var(--l-muted)]">
                        {row.project.client?.name ?? t("common.na")}
                        {row.project.code ? ` · ${row.project.code}` : ""}
                      </p>
                    </div>
                    {canSeeMoney ? (
                      <>
                        <div className="col-span-2 row-start-2 min-w-0 sm:col-span-1 sm:row-start-auto">
                          {row.ratio === null ? (
                            <p className="text-xs text-[var(--l-muted)]">{t("dashboard.collection.noContract")}</p>
                          ) : (
                            <>
                              <Track
                                ratio={row.ratio}
                                label={fill("dashboard.collection.trackLabel", { name: row.project.name })}
                              />
                              <p className="mt-1.5 text-xs tabular-nums text-[var(--l-muted)]">
                                {fill("dashboard.collection.ofContract", {
                                  collected: formatMoney(row.collected),
                                  value: formatMoney(row.value),
                                })}
                              </p>
                            </>
                          )}
                        </div>
                        <div className="text-end">
                          <p className="text-sm font-semibold tabular-nums">
                            {row.ratio === null ? <Dash /> : formatMoney(row.outstanding)}
                          </p>
                          <p className="text-xs text-[var(--l-muted)]">{t("dashboard.collection.left")}</p>
                        </div>
                      </>
                    ) : (
                      <p className="text-end text-xs text-[var(--l-muted)] sm:col-span-2">
                        {row.project.responsible_user?.name ?? "—"}
                      </p>
                    )}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel className="lg:col-span-4" title={t("dashboard.finish.title")} meta={t("dashboard.finish.meta")}>
          {active.state === "loading" ? (
            <SkeletonRows rows={4} />
          ) : active.state === "error" ? (
            <BlockError message={active.message} onRetry={load} retryLabel={t("common.retry")} />
          ) : finish.length === 0 ? (
            <EmptyLine>{t("dashboard.finish.empty")}</EmptyLine>
          ) : (
            <ul className="-mx-2">
              {finish.slice(0, LIST_CAP).map((row) => (
                <li key={row.project.id} className="border-t border-[var(--l-rule)] first:border-t-0">
                  <Link
                    href={`/projects/${row.project.id}`}
                    className="flex items-center justify-between gap-4 rounded-md px-2 py-3 outline-1 -outline-offset-1 outline-transparent hover:outline-[var(--l-border)] focus-visible:outline-2 focus-visible:outline-[var(--l-text)]"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{row.project.name}</p>
                      <p className="truncate text-xs text-[var(--l-muted)]">
                        {formatDate(row.project.target_end_date, locale)}
                        {row.project.status === "on_hold" ? ` · ${statusLabel("on_hold")}` : ""}
                      </p>
                    </div>
                    <FinishDelta row={row} fill={fill} t={t} />
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel
          className="lg:col-span-8"
          title={t("dashboard.recent.title")}
          action={
            recent.state === "ready" && recent.data.total > LIST_CAP
              ? { href: "/projects", label: fill("dashboard.viewAllCount", { count: recent.data.total }) }
              : undefined
          }
        >
          {recent.state === "loading" ? (
            <SkeletonRows rows={4} />
          ) : recent.state === "error" ? (
            <BlockError message={recent.message} onRetry={load} retryLabel={t("common.retry")} />
          ) : recent.data.rows.length === 0 ? (
            <EmptyLine>{t("dashboard.projects.empty")}</EmptyLine>
          ) : (
            <table className="w-full table-fixed text-sm">
              <thead>
                <tr className="text-xs text-[var(--l-muted)]">
                  <th scope="col" className="w-[44%] pb-2 text-start font-normal">{t("dashboard.recent.project")}</th>
                  <th scope="col" className="hidden pb-2 text-start font-normal sm:table-cell">{t("dashboard.recent.client")}</th>
                  <th scope="col" className="w-28 pb-2 text-start font-normal">{t("dashboard.recent.status")}</th>
                  <th scope="col" className="hidden w-28 pb-2 text-end font-normal sm:table-cell">{t("dashboard.recent.added")}</th>
                </tr>
              </thead>
              <tbody>
                {[...recent.data.rows]
                  .sort((a, b) => b.created_at.localeCompare(a.created_at))
                  .slice(0, LIST_CAP)
                  .map((project) => (
                    <tr key={project.id} className="border-t border-[var(--l-rule)]">
                      <td className="py-2.5 pe-4">
                        <Link
                          href={`/projects/${project.id}`}
                          className="block truncate font-medium hover:underline focus-visible:outline-2 focus-visible:outline-[var(--l-text)]"
                        >
                          {project.name}
                        </Link>
                        <span className="block truncate text-xs text-[var(--l-muted)] sm:hidden">
                          {project.client?.name ?? t("common.na")}
                        </span>
                      </td>
                      <td className="hidden truncate py-2.5 pe-4 text-[var(--l-muted)] sm:table-cell">
                        {project.client?.name ?? t("common.na")}
                      </td>
                      <td className="py-2.5">
                        <StatusPill status={project.status} label={statusLabel(project.status)} />
                      </td>
                      <td className="hidden py-2.5 text-end tabular-nums text-[var(--l-muted)] sm:table-cell">
                        {formatDate(project.created_at, locale)}
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
          )}
        </Panel>

        <Panel
          className="lg:col-span-4"
          title={t("dashboard.leads.title")}
          meta={t("dashboard.leads.meta")}
          action={{ href: "/leads", label: t("dashboard.viewAll") }}
        >
          {leads.state === "loading" ? (
            <SkeletonRows rows={3} />
          ) : leads.state === "error" ? (
            <BlockError message={leads.message} onRetry={load} retryLabel={t("common.retry")} />
          ) : (
            <LeadsBlock leads={leads.data} formatMoney={formatMoney} num={num} t={t} />
          )}
        </Panel>
      </div>
    </div>
  );
}

function Dash() {
  return <span className="text-[var(--l-muted)]">—</span>;
}

function SkeletonRows({ rows }: { rows: number }) {
  return (
    <div aria-hidden="true">
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="flex items-center justify-between gap-4 py-3">
          <div className="flex flex-col gap-1.5">
            <Bar className="h-4 w-44" />
            <Bar className="h-3 w-28" />
          </div>
          <Bar className="h-4 w-20" />
        </div>
      ))}
    </div>
  );
}

type Fill = (key: TranslationKey, values: Record<string, string | number>) => string;

function FinishDelta({ row, fill, t }: { row: FinishRow; fill: Fill; t: (key: TranslationKey) => string }) {
  if (row.days < 0) {
    const late = -row.days;
    return (
      <span className="shrink-0 text-sm font-medium tabular-nums text-[var(--l-negative)]">
        {late === 1 ? t("dashboard.finish.lateOne") : fill("dashboard.finish.lateMany", { count: late })}
      </span>
    );
  }
  return (
    <span className="shrink-0 text-sm tabular-nums text-[var(--l-muted)]">
      {row.days === 0
        ? t("dashboard.finish.today")
        : row.days === 1
          ? t("dashboard.finish.inOne")
          : fill("dashboard.finish.inMany", { count: row.days })}
    </span>
  );
}

function LeadsBlock({
  leads,
  formatMoney,
  num,
  t,
}: {
  leads: Lead[];
  formatMoney: (amount: number) => string;
  num: (n: number) => string;
  t: (key: TranslationKey) => string;
}) {
  const stages = leadStages(leads);
  const total = stages.reduce((sum, s) => sum + s.count, 0);
  if (total === 0) return <EmptyLine>{t("dashboard.leads.empty")}</EmptyLine>;
  const max = Math.max(...stages.map((s) => s.count));

  return (
    <ul className="flex flex-col gap-3">
      {stages.map((stage) => (
        <li key={stage.status}>
          <div className="flex items-baseline justify-between gap-3 text-sm">
            <span>{t(`leads.column.${stage.status}` as TranslationKey)}</span>
            <span className="tabular-nums">
              <span className="font-medium">{num(stage.count)}</span>
              {stage.budget > 0 ? (
                <span className="ms-2 text-xs text-[var(--l-muted)]">{formatMoney(stage.budget)}</span>
              ) : null}
            </span>
          </div>
          {/* Thin grey rank bar, true to the count; the amber stays reserved for collected money. */}
          <div className="mt-1.5 h-0.5 w-full">
            {stage.count > 0 ? (
              <div className="h-full bg-[var(--l-muted)] opacity-50" style={{ width: `${(stage.count / max) * 100}%` }} />
            ) : null}
          </div>
        </li>
      ))}
    </ul>
  );
}
