"use client";

/**
 * S08 — Pricing Panel (PRD §4, PROJECT_CONTEXT.md Sprint 3 UX). Rendered as a section within the
 * existing "BOQ & Pricing" tab (see app/(protected)/projects/[id]/boq/page.tsx), below the BOQ
 * grid, so the two halves of that tab read as one workspace rather than a separate route.
 *
 * `pricing_rules` are project-level layers (markup/fee/discount) applied on top of the BOQ's
 * summed line-item pricing — they do NOT replace each boq_item's own client_unit_price. See
 * docs/PROJECT_CONTEXT.md Sprint 3 scope for the full mental model.
 *
 * Internal/client view toggle: per the PRD's "never leak internal margin to client" requirement
 * (also the locked product decision in PROJECT_CONTEXT.md), switching to "client preview" swaps
 * in <PricingClientView>, a component that is only ever given the grand total + priced flag —
 * never the full breakdown object. This isn't just an optics choice: it makes it structurally
 * impossible for the client-view render path to leak rule/cost data into the DOM, rather than
 * relying on conditional JSX inside a single component (which a future edit could accidentally
 * widen to expose more than intended). There is no real client user of this internal screen, but
 * the sprint scope explicitly calls out building the habit now.
 */

import { useEffect, useState, type Dispatch, type FormEvent, type ReactNode, type SetStateAction } from "react";
import { ApiError } from "@/lib/api/client";
import {
  createPricingRule,
  deletePricingRule,
  getPricingBreakdown,
  getPricingRules,
  recalculatePricing,
  updatePricingRule,
} from "@/lib/api/resources/pricing";
import type {
  PricingBaseSelector,
  PricingBreakdown,
  PricingMethod,
  PricingRule,
  PricingRuleFormInput,
  PricingRuleType,
} from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { Badge } from "@/components/ui/Badge";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { formatEGP, type Locale } from "@/lib/format/currency";
import { formatDateTime } from "@/lib/format/date";

const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";

const TYPE_OPTIONS: PricingRuleType[] = ["markup", "fee", "discount"];
const METHOD_OPTIONS: PricingMethod[] = ["percentage", "fixed_amount"];
const BASE_OPTIONS: PricingBaseSelector[] = ["boq_direct_cost", "boq_client_subtotal", "running_subtotal"];

const TYPE_BADGE_TONE: Record<PricingRuleType, "blue" | "amber" | "red"> = {
  markup: "blue",
  fee: "amber",
  discount: "red",
};

type T = (key: TranslationKey) => string;

function formatRuleValue(rule: Pick<PricingRule, "method" | "value">, locale: Locale): string {
  return rule.method === "percentage" ? `${rule.value}%` : formatEGP(rule.value, locale);
}

interface PricingPanelProps {
  projectId: string;
  /** Notifies the parent (BOQ Builder page) after a successful recalculate, e.g. to refresh the
   *  project header if it displays the grand total elsewhere. Optional — the panel is fully
   *  self-sufficient without it. */
  onRecalculated?: (breakdown: PricingBreakdown) => void;
}

export function PricingPanel({ projectId, onRecalculated }: PricingPanelProps) {
  const { t, locale } = useLocale();

  const [rules, setRules] = useState<PricingRule[]>([]);
  const [breakdown, setBreakdown] = useState<PricingBreakdown | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [viewMode, setViewMode] = useState<"internal" | "client">("internal");
  const [recalculating, setRecalculating] = useState(false);

  async function load() {
    setLoading(true);
    setLoadError(null);
    try {
      const [rulesData, breakdownData] = await Promise.all([
        getPricingRules(projectId),
        getPricingBreakdown(projectId),
      ]);
      setRules(rulesData);
      setBreakdown(breakdownData);
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount, matching every other screen in this app (see boq/page.tsx).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  async function handleRecalculate() {
    setRecalculating(true);
    setActionError(null);
    try {
      const result = await recalculatePricing(projectId);
      setBreakdown(result);
      onRecalculated?.(result);
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("pricing.recalculate.failed"));
    } finally {
      setRecalculating(false);
    }
  }

  function upsertRuleLocal(updated: PricingRule) {
    setRules((prev) => prev.map((r) => (String(r.id) === String(updated.id) ? updated : r)));
  }

  /**
   * Re-fetches the live breakdown preview (not a recalculate — no persistence) after any rule
   * mutation. Mirrors PricingController::breakdown()'s documented intent: "adding/editing/
   * deactivating a rule is immediately visible here without forcing a recalculate first." Errors
   * are swallowed — a stale breakdown from a failed background refresh is a much smaller problem
   * than surfacing a scary error banner for a read that isn't even the action the user took.
   */
  async function refreshBreakdown() {
    try {
      setBreakdown(await getPricingBreakdown(projectId));
    } catch {
      // Non-critical background refresh — the rule mutation itself already succeeded and is
      // reflected in `rules`; the breakdown will simply stay stale until the next load/action.
    }
  }

  async function handleToggleActive(rule: PricingRule) {
    setActionError(null);
    try {
      const updated = await updatePricingRule(rule.id, { active: !rule.active });
      upsertRuleLocal(updated);
      await refreshBreakdown();
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  async function handleDeleteRule(rule: PricingRule) {
    if (!window.confirm(t("pricing.rules.deleteConfirm"))) return;
    setActionError(null);
    try {
      await deletePricingRule(rule.id);
      setRules((prev) => prev.filter((r) => String(r.id) !== String(rule.id)));
      await refreshBreakdown();
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  if (loading) return <LoadingScreen label={t("common.loading")} />;

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("pricing.title")}</h2>
            <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("pricing.subtitle")}</p>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <ViewModeToggle mode={viewMode} onChange={setViewMode} t={t} />
            <Button onClick={handleRecalculate} disabled={recalculating}>
              {recalculating ? t("pricing.recalculate.running") : t("pricing.recalculate.cta")}
            </Button>
          </div>
        </CardHeader>
        <CardBody className="flex flex-col gap-2 text-xs text-zinc-500 dark:text-zinc-400">
          <span>
            {t("pricing.priced.at")}:{" "}
            {breakdown?.priced_at ? formatDateTime(breakdown.priced_at, locale) : t("pricing.priced.never")}
          </span>
          {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}
          {actionError ? <ErrorBanner message={actionError} /> : null}
        </CardBody>
      </Card>

      {viewMode === "client" ? (
        <PricingClientView
          priced={breakdown?.priced ?? false}
          grandTotal={breakdown?.grand_total ?? null}
          t={t}
          locale={locale}
        />
      ) : (
        <>
          <PricingRulesCard
            projectId={projectId}
            rules={rules}
            setRules={setRules}
            onToggleActive={handleToggleActive}
            onDelete={handleDeleteRule}
            onRuleSaved={refreshBreakdown}
            setActionError={setActionError}
            t={t}
            locale={locale}
          />
          <PricingBreakdownCard breakdown={breakdown} t={t} locale={locale} />
        </>
      )}
    </div>
  );
}

function ViewModeToggle({
  mode,
  onChange,
  t,
}: {
  mode: "internal" | "client";
  onChange: (mode: "internal" | "client") => void;
  t: T;
}) {
  return (
    <div className="flex flex-col items-end gap-1">
      <div className="inline-flex rounded-md border border-zinc-300 p-0.5 text-sm dark:border-zinc-700">
        <button
          type="button"
          onClick={() => onChange("internal")}
          className={`rounded px-3 py-1 font-medium transition-colors ${
            mode === "internal"
              ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
              : "text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
          }`}
        >
          {t("pricing.viewToggle.internal")}
        </button>
        <button
          type="button"
          onClick={() => onChange("client")}
          className={`rounded px-3 py-1 font-medium transition-colors ${
            mode === "client"
              ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
              : "text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
          }`}
        >
          {t("pricing.viewToggle.client")}
        </button>
      </div>
      {mode === "client" ? (
        <p className="max-w-xs text-right text-xs text-amber-600 dark:text-amber-400">
          {t("pricing.viewToggle.clientNote")}
        </p>
      ) : null}
    </div>
  );
}

/**
 * Renders ONLY the final grand total — never given the rule list, cost totals, or margin data
 * (see PricingPanel's docblock for why this is a separate component rather than a conditional
 * branch that happens to hide fields).
 */
function PricingClientView({
  priced,
  grandTotal,
  t,
  locale,
}: {
  priced: boolean;
  grandTotal: number | string | null;
  t: T;
  locale: Locale;
}) {
  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("pricing.clientView.title")}</h2>
        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{t("pricing.clientView.subtitle")}</p>
      </CardHeader>
      <CardBody>
        {priced ? (
          <p className="text-3xl font-semibold text-zinc-900 dark:text-zinc-50">{formatEGP(grandTotal, locale)}</p>
        ) : (
          <div className="py-6 text-center">
            <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("pricing.empty.title")}</p>
            <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("pricing.empty.action")}</p>
          </div>
        )}
      </CardBody>
    </Card>
  );
}

function PricingBreakdownCard({
  breakdown,
  t,
  locale,
}: {
  breakdown: PricingBreakdown | null;
  t: T;
  locale: Locale;
}) {
  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("pricing.breakdown.title")}</h2>
      </CardHeader>
      <CardBody>
        {!breakdown || !breakdown.priced ? (
          <div className="py-8 text-center">
            <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("pricing.empty.title")}</p>
            <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("pricing.empty.action")}</p>
          </div>
        ) : (
          <div className="flex flex-col gap-4 text-sm">
            <div className="flex items-baseline justify-between">
              <span className="text-zinc-600 dark:text-zinc-300">{t("pricing.breakdown.directCost")}</span>
              <span className="font-medium text-zinc-900 dark:text-zinc-100">
                {formatEGP(breakdown.direct_cost_total, locale)}
              </span>
            </div>
            <div className="flex items-baseline justify-between border-b border-zinc-200 pb-4 dark:border-zinc-800">
              <span className="text-zinc-600 dark:text-zinc-300">{t("pricing.breakdown.clientSubtotal")}</span>
              <span className="font-medium text-zinc-900 dark:text-zinc-100">
                {formatEGP(breakdown.client_subtotal, locale)}
              </span>
            </div>

            <div>
              <p className="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-400">
                {t("pricing.breakdown.rulesTitle")}
              </p>
              {breakdown.rules.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("pricing.breakdown.rulesEmpty")}</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[640px] border-collapse text-sm">
                    <thead>
                      <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                        <th className="px-2 py-2">{t("pricing.rules.columns.name")}</th>
                        <th className="px-2 py-2">{t("pricing.rules.columns.type")}</th>
                        <th className="px-2 py-2 text-right">{t("pricing.breakdown.columns.baseAmount")}</th>
                        <th className="px-2 py-2 text-right">{t("pricing.rules.columns.value")}</th>
                        <th className="px-2 py-2 text-right">{t("pricing.breakdown.runningAfter")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {breakdown.rules.map((rule) => (
                        <tr
                          key={rule.id}
                          className="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60"
                        >
                          <td className="px-2 py-2 align-top">
                            <p className="font-medium text-zinc-900 dark:text-zinc-100">{rule.name}</p>
                            <p className="text-xs text-zinc-400">{t(`pricing.rules.base.${rule.base_selector}`)}</p>
                          </td>
                          <td className="px-2 py-2 align-top">
                            <Badge tone={TYPE_BADGE_TONE[rule.type]}>{t(`pricing.rules.type.${rule.type}`)}</Badge>
                          </td>
                          <td className="whitespace-nowrap px-2 py-2 text-right align-top text-zinc-500 dark:text-zinc-400">
                            {formatEGP(rule.base_amount_used, locale)}
                          </td>
                          <td className="whitespace-nowrap px-2 py-2 text-right align-top font-medium text-zinc-900 dark:text-zinc-100">
                            {rule.type === "discount" ? "− " : "+ "}
                            {formatEGP(rule.computed_amount, locale)}
                            <span className="ml-1 text-xs font-normal text-zinc-400">
                              ({formatRuleValue(rule, locale)})
                            </span>
                          </td>
                          <td className="whitespace-nowrap px-2 py-2 text-right align-top font-medium text-zinc-900 dark:text-zinc-100">
                            {formatEGP(rule.running_subtotal_after, locale)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            <div className="flex flex-col gap-1 border-t border-zinc-200 pt-3 dark:border-zinc-800">
              <div className="flex items-baseline justify-between text-zinc-600 dark:text-zinc-300">
                <span>{t("pricing.breakdown.markupTotal")}</span>
                <span>{formatEGP(breakdown.markup_total, locale)}</span>
              </div>
              <div className="flex items-baseline justify-between text-zinc-600 dark:text-zinc-300">
                <span>{t("pricing.breakdown.feesTotal")}</span>
                <span>{formatEGP(breakdown.fees_total, locale)}</span>
              </div>
              <div className="flex items-baseline justify-between text-zinc-600 dark:text-zinc-300">
                <span>{t("pricing.breakdown.discountTotal")}</span>
                <span>− {formatEGP(breakdown.discount_total, locale)}</span>
              </div>
            </div>

            <div className="flex items-baseline justify-between border-t border-zinc-200 pt-3 dark:border-zinc-800">
              <span className="text-base font-semibold text-zinc-900 dark:text-zinc-50">
                {t("pricing.breakdown.grandTotal")}
              </span>
              <span className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">
                {formatEGP(breakdown.grand_total, locale)}
              </span>
            </div>
          </div>
        )}
      </CardBody>
    </Card>
  );
}

interface PricingRulesCardProps {
  projectId: string;
  rules: PricingRule[];
  setRules: Dispatch<SetStateAction<PricingRule[]>>;
  onToggleActive: (rule: PricingRule) => void;
  onDelete: (rule: PricingRule) => void;
  /** Re-fetches the live breakdown preview after a rule is created/edited — see
   *  PricingPanel.refreshBreakdown's docblock for why this matters. */
  onRuleSaved: () => Promise<void>;
  setActionError: (message: string | null) => void;
  t: T;
  locale: Locale;
}

function PricingRulesCard({
  projectId,
  rules,
  setRules,
  onToggleActive,
  onDelete,
  onRuleSaved,
  setActionError,
  t,
  locale,
}: PricingRulesCardProps) {
  const [showAddRule, setShowAddRule] = useState(false);
  const [editingRuleId, setEditingRuleId] = useState<string | null>(null);

  async function handleCreate(input: PricingRuleFormInput) {
    const created = await createPricingRule(projectId, input);
    setRules((prev) => [...prev, created].sort((a, b) => a.sort_order - b.sort_order));
    setShowAddRule(false);
    await onRuleSaved();
  }

  async function handleUpdate(rule: PricingRule, input: PricingRuleFormInput) {
    const updated = await updatePricingRule(rule.id, input);
    setRules((prev) => prev.map((r) => (String(r.id) === String(updated.id) ? updated : r)));
    setEditingRuleId(null);
    await onRuleSaved();
  }

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("pricing.rules.title")}</h2>
        <Button
          variant="secondary"
          className="py-1"
          onClick={() => {
            setEditingRuleId(null);
            setShowAddRule((v) => !v);
          }}
        >
          {t("pricing.rules.add.cta")}
        </Button>
      </CardHeader>

      {showAddRule ? (
        <CardBody className="border-b border-zinc-200 dark:border-zinc-800">
          <p className="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("pricing.rules.add.title")}</p>
          <RuleForm
            nextSortOrder={rules.length}
            submitLabel={t("pricing.rules.form.submit")}
            submittingLabel={t("pricing.rules.form.submitting")}
            onSubmit={handleCreate}
            onCancel={() => setShowAddRule(false)}
            setActionError={setActionError}
            t={t}
          />
        </CardBody>
      ) : null}

      <CardBody className="overflow-x-auto p-0">
        {rules.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{t("pricing.rules.empty")}</p>
        ) : (
          <table className="w-full min-w-[760px] border-collapse text-sm">
            <thead>
              <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                <th className="px-3 py-2">{t("pricing.rules.columns.name")}</th>
                <th className="px-3 py-2">{t("pricing.rules.columns.type")}</th>
                <th className="px-3 py-2">{t("pricing.rules.columns.method")}</th>
                <th className="px-3 py-2 text-right">{t("pricing.rules.columns.value")}</th>
                <th className="px-3 py-2">{t("pricing.rules.columns.base")}</th>
                <th className="px-3 py-2">{t("pricing.rules.columns.status")}</th>
                <th className="px-3 py-2">{t("pricing.rules.columns.actions")}</th>
              </tr>
            </thead>
            <tbody>
              {rules.map((rule) =>
                editingRuleId === String(rule.id) ? (
                  <tr key={rule.id} className="border-b border-zinc-100 dark:border-zinc-800/60">
                    <td colSpan={7} className="px-3 py-3">
                      <p className="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-200">
                        {t("pricing.rules.editTitle")}
                      </p>
                      <RuleForm
                        initial={rule}
                        nextSortOrder={rule.sort_order}
                        submitLabel={t("pricing.rules.form.save")}
                        submittingLabel={t("pricing.rules.form.saving")}
                        onSubmit={(input) => handleUpdate(rule, input)}
                        onCancel={() => setEditingRuleId(null)}
                        setActionError={setActionError}
                        t={t}
                      />
                    </td>
                  </tr>
                ) : (
                  <tr
                    key={rule.id}
                    className="border-b border-zinc-100 last:border-0 hover:bg-zinc-50 dark:border-zinc-800/60 dark:hover:bg-zinc-800/30"
                  >
                    <td className="px-3 py-2 align-top font-medium text-zinc-900 dark:text-zinc-100">{rule.name}</td>
                    <td className="px-3 py-2 align-top">
                      <Badge tone={TYPE_BADGE_TONE[rule.type]}>{t(`pricing.rules.type.${rule.type}`)}</Badge>
                    </td>
                    <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">
                      {t(`pricing.rules.method.${rule.method}`)}
                    </td>
                    <td className="px-3 py-2 text-right align-top text-zinc-900 dark:text-zinc-100">
                      {formatRuleValue(rule, locale)}
                    </td>
                    <td className="px-3 py-2 align-top text-xs text-zinc-500 dark:text-zinc-400">
                      {t(`pricing.rules.base.${rule.base_selector}`)}
                    </td>
                    <td className="px-3 py-2 align-top">
                      <Badge tone={rule.active ? "green" : "neutral"}>
                        {rule.active ? t("pricing.rules.status.active") : t("pricing.rules.status.inactive")}
                      </Badge>
                    </td>
                    <td className="whitespace-nowrap px-3 py-2 align-top">
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => {
                            setShowAddRule(false);
                            setEditingRuleId(String(rule.id));
                          }}
                          className="text-xs font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100"
                        >
                          {t("pricing.rules.action.edit")}
                        </button>
                        <button
                          type="button"
                          onClick={() => onToggleActive(rule)}
                          className="text-xs font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100"
                        >
                          {rule.active ? t("pricing.rules.action.deactivate") : t("pricing.rules.action.activate")}
                        </button>
                        <button
                          type="button"
                          onClick={() => onDelete(rule)}
                          className="text-xs font-medium text-red-500 hover:text-red-700"
                        >
                          {t("pricing.rules.action.delete")}
                        </button>
                      </div>
                    </td>
                  </tr>
                ),
              )}
            </tbody>
          </table>
        )}
      </CardBody>
    </Card>
  );
}

interface RuleFormProps {
  initial?: PricingRule;
  nextSortOrder: number;
  submitLabel: string;
  submittingLabel: string;
  onSubmit: (input: PricingRuleFormInput) => Promise<void>;
  onCancel: () => void;
  setActionError: (message: string | null) => void;
  t: T;
}

/** Shared add/edit form for a pricing rule — plain `<select>`s for the three enum fields with
 *  human-readable labels (see dictionary `pricing.rules.type.*` / `method.*` / `base.*`), per the
 *  sprint's explicit requirement to make `base_selector` understandable to a non-technical
 *  designer rather than exposing the raw enum values. */
function RuleForm({ initial, nextSortOrder, submitLabel, submittingLabel, onSubmit, onCancel, setActionError, t }: RuleFormProps) {
  const [name, setName] = useState(initial?.name ?? "");
  const [type, setType] = useState<PricingRuleType>(initial?.type ?? "markup");
  const [method, setMethod] = useState<PricingMethod>(initial?.method ?? "percentage");
  const [value, setValue] = useState(initial ? String(initial.value) : "");
  const [baseSelector, setBaseSelector] = useState<PricingBaseSelector>(initial?.base_selector ?? "boq_client_subtotal");
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    setActionError(null);
    setSubmitting(true);
    try {
      await onSubmit({
        name,
        type,
        method,
        value,
        base_selector: baseSelector,
        sort_order: initial?.sort_order ?? nextSortOrder,
      });
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-3">
      <FormField label={t("pricing.rules.form.name")} htmlFor="rule-name">
        <input
          id="rule-name"
          type="text"
          required
          placeholder={t("pricing.rules.form.namePlaceholder")}
          value={name}
          onChange={(e) => setName(e.target.value)}
          className={`${INPUT_CLASSES} w-56`}
        />
      </FormField>
      <FormField label={t("pricing.rules.form.type")} htmlFor="rule-type">
        <select
          id="rule-type"
          value={type}
          onChange={(e) => setType(e.target.value as PricingRuleType)}
          className={INPUT_CLASSES}
        >
          {TYPE_OPTIONS.map((opt) => (
            <option key={opt} value={opt}>
              {t(`pricing.rules.type.${opt}`)}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={t("pricing.rules.form.method")} htmlFor="rule-method">
        <select
          id="rule-method"
          value={method}
          onChange={(e) => setMethod(e.target.value as PricingMethod)}
          className={INPUT_CLASSES}
        >
          {METHOD_OPTIONS.map((opt) => (
            <option key={opt} value={opt}>
              {t(`pricing.rules.method.${opt}`)}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={t("pricing.rules.form.value")} htmlFor="rule-value">
        <div className="flex items-center gap-1">
          <input
            id="rule-value"
            type="number"
            min="0"
            step="0.01"
            required
            value={value}
            onChange={(e) => setValue(e.target.value)}
            className={`${INPUT_CLASSES} w-28`}
          />
          <span className="text-xs text-zinc-400">
            {method === "percentage" ? t("pricing.rules.form.valuePercentHint") : t("pricing.rules.form.valueFixedHint")}
          </span>
        </div>
      </FormField>
      <FormField label={t("pricing.rules.form.base")} htmlFor="rule-base">
        <select
          id="rule-base"
          value={baseSelector}
          onChange={(e) => setBaseSelector(e.target.value as PricingBaseSelector)}
          className={`${INPUT_CLASSES} max-w-[260px]`}
        >
          {BASE_OPTIONS.map((opt) => (
            <option key={opt} value={opt}>
              {t(`pricing.rules.base.${opt}`)}
            </option>
          ))}
        </select>
      </FormField>
      <div className="flex items-center gap-2">
        <Button type="submit" disabled={submitting}>
          {submitting ? submittingLabel : submitLabel}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("common.cancel")}
        </Button>
      </div>
      {formError ? (
        <div className="w-full">
          <ErrorBanner message={formError} />
        </div>
      ) : null}
    </form>
  );
}

function FormField({ label, htmlFor, children }: { label: string; htmlFor: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={htmlFor} className="text-xs font-medium text-zinc-500 dark:text-zinc-400">
        {label}
      </label>
      {children}
    </div>
  );
}
