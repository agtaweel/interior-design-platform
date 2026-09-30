"use client";

/**
 * S21 — Reports (PROJECT_CONTEXT.md Sprint 8 "Reports"). Organization-level, not project-scoped
 * — lives alongside Dashboard/Clients/Projects, not inside a project's tab layout. Both backing
 * endpoints require Permissions::VIEW_FINANCIALS server-side; a 403 here is rendered as a plain
 * "you don't have permission" message rather than the generic ErrorBanner retry affordance,
 * since retrying won't change the outcome.
 *
 * `estimated_margin` is deliberately labeled "Estimated Margin" (never "Profit") everywhere on
 * this page — it's derived from BOQ pricing (grand_total - direct_cost_total), not real
 * expenses, per PROJECT_CONTEXT.md's explicit instruction.
 */

import { useEffect, useMemo, useState } from "react";
import { ApiError } from "@/lib/api/client";
import { exportProjectsReport, getReportProjects, getReportSummary } from "@/lib/api/resources/reports";
import type { ReportProjectRow, ReportSummary } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { KpiCard } from "@/components/ui/KpiCard";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { useMoneyFormatter } from "@/lib/format/useMoneyFormatter";

type SortColumn =
  | "name"
  | "code"
  | "status"
  | "contract_value"
  | "collected"
  | "outstanding"
  | "estimated_margin"
  | "change_order_value"
  | "budget_variance";

interface SortState {
  column: SortColumn;
  direction: "asc" | "desc";
}

const COLUMNS: { key: SortColumn; labelKey: TranslationKey; numeric: boolean }[] = [
  { key: "name", labelKey: "reports.table.columns.name", numeric: false },
  { key: "code", labelKey: "reports.table.columns.code", numeric: false },
  { key: "status", labelKey: "reports.table.columns.status", numeric: false },
  { key: "contract_value", labelKey: "reports.table.columns.contractValue", numeric: true },
  { key: "collected", labelKey: "reports.table.columns.collected", numeric: true },
  { key: "outstanding", labelKey: "reports.table.columns.outstanding", numeric: true },
  { key: "estimated_margin", labelKey: "reports.table.columns.estimatedMargin", numeric: true },
  { key: "change_order_value", labelKey: "reports.table.columns.changeOrderValue", numeric: true },
  { key: "budget_variance", labelKey: "reports.table.columns.budgetVariance", numeric: true },
];

function toNumber(value: number | string): number {
  return typeof value === "string" ? Number(value) : value;
}

export default function ReportsPage() {
  const { t, locale } = useLocale();
  const { formatMoney } = useMoneyFormatter();
  const [summary, setSummary] = useState<ReportSummary | null>(null);
  const [rows, setRows] = useState<ReportProjectRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [sort, setSort] = useState<SortState>({ column: "name", direction: "asc" });
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const [summaryResult, rowsResult] = await Promise.all([getReportSummary(), getReportProjects()]);
      setSummary(summaryResult);
      setRows(rowsResult);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) {
        setForbidden(true);
      } else {
        setError(err instanceof ApiError ? err.message : t("common.unknownError"));
      }
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount, see dashboard/page.tsx for rationale.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const sortedRows = useMemo(() => {
    const copy = [...rows];
    copy.sort((a, b) => {
      const col = sort.column;
      let cmp: number;
      if (col === "name" || col === "code" || col === "status") {
        cmp = String(a[col] ?? "").localeCompare(String(b[col] ?? ""));
      } else {
        cmp = toNumber(a[col]) - toNumber(b[col]);
      }
      return sort.direction === "asc" ? cmp : -cmp;
    });
    return copy;
  }, [rows, sort]);

  function toggleSort(column: SortColumn) {
    setSort((prev) =>
      prev.column === column
        ? { column, direction: prev.direction === "asc" ? "desc" : "asc" }
        : { column, direction: "asc" },
    );
  }

  async function handleExport() {
    setExporting(true);
    setExportError(null);
    try {
      await exportProjectsReport();
    } catch {
      setExportError(t("reports.export.failed"));
    } finally {
      setExporting(false);
    }
  }

  if (loading) {
    return <LoadingScreen label={t("common.loading")} />;
  }

  if (forbidden) {
    return (
      <div className="flex flex-col gap-6">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("reports.title")}</h1>
        <ErrorBanner message={t("reports.forbidden")} />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("reports.title")}</h1>
        <Button variant="secondary" onClick={handleExport} disabled={exporting}>
          {exporting ? t("reports.export.exporting") : t("reports.export.cta")}
        </Button>
      </div>

      {error ? <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} /> : null}
      {exportError ? <ErrorBanner message={exportError} /> : null}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <KpiCard label={t("reports.kpi.totalRevenue")} value={formatMoney(summary?.total_revenue)} />
        <KpiCard
          label={t("reports.kpi.totalReceivables")}
          value={formatMoney(summary?.total_receivables)}
        />
        <KpiCard
          label={t("reports.kpi.estimatedMargin")}
          value={formatMoney(summary?.estimated_margin)}
          hint={t("reports.kpi.estimatedMarginHint")}
        />
        <KpiCard
          label={t("reports.kpi.changeOrderValue")}
          value={formatMoney(summary?.total_change_order_value)}
        />
        <KpiCard
          label={t("reports.kpi.projects")}
          value={`${summary?.active_project_count ?? 0} / ${summary?.project_count ?? 0}`}
        />
      </div>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("reports.table.title")}</h2>
        </CardHeader>
        <CardBody className="p-0">
          {sortedRows.length === 0 ? (
            <EmptyState message={t("reports.table.empty")} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm rtl:text-right">
                <thead className="border-b border-zinc-200 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                  <tr>
                    {COLUMNS.map((col) => (
                      <th key={col.key} className="whitespace-nowrap px-4 py-2 font-medium">
                        <button
                          type="button"
                          onClick={() => toggleSort(col.key)}
                          className="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100"
                        >
                          {t(col.labelKey)}
                          {sort.column === col.key ? (sort.direction === "asc" ? "▲" : "▼") : null}
                        </button>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
                  {sortedRows.map((row) => (
                    <tr key={`${row.code ?? row.name}`}>
                      <td className="whitespace-nowrap px-4 py-2 font-medium text-zinc-900 dark:text-zinc-50">
                        {row.name}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 text-zinc-500 dark:text-zinc-400">
                        {row.code ?? "—"}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2">
                        <StatusBadge status={row.status} />
                      </td>
                      <td className="whitespace-nowrap px-4 py-2">{formatMoney(row.contract_value)}</td>
                      <td className="whitespace-nowrap px-4 py-2">{formatMoney(row.collected)}</td>
                      <td className="whitespace-nowrap px-4 py-2">{formatMoney(row.outstanding)}</td>
                      <td className="whitespace-nowrap px-4 py-2">{formatMoney(row.estimated_margin)}</td>
                      <td className="whitespace-nowrap px-4 py-2">{formatMoney(row.change_order_value)}</td>
                      <td className="whitespace-nowrap px-4 py-2">{formatMoney(row.budget_variance)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
