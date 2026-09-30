"use client";

/**
 * S13 — Payments (PROJECT_CONTEXT.md Sprint 6). Mirrors the Contract tab's state-resolution
 * pattern (contract/page.tsx): a single fetch of `GET /projects/{id}/contracts` up front tells
 * this screen whether there's anything to show at all.
 *
 *   1. No contract yet -> empty state pointing at the Contract tab (mirrors contract/page.tsx's
 *      own empty state for "no approved proposal yet").
 *   2. A contract exists -> a receivables table of its payment schedules (name, due date,
 *      amount, status badge) with All/Overdue/Upcoming/Paid filter tabs, an "Add Schedule" form
 *      (percentage-of-contract-value vs fixed-amount toggle, mirroring PricingPanel's
 *      internal/client ViewModeToggle pill pattern), and a per-row "Manage" expansion showing
 *      the Record Payment form + payment history/receipts list.
 *
 * Status semantics (critical, see docs/PROJECT_CONTEXT.md Sprint 6 schema notes): the backend
 * only ever stores `pending`/`paid` on a payment_schedule. "Overdue" and "upcoming" are NOT
 * separate stored states — they're computed at read time and exposed as `is_overdue`/
 * `is_upcoming`/`is_paid` booleans on every schedule (see PaymentScheduleResource). This screen
 * always filters/badges off those three booleans, never off the raw `status` string.
 */

import { Fragment, useEffect, useState, type FormEvent, type ReactNode } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import {
  createPaymentSchedule,
  downloadPaymentReceipt,
  getPaymentSchedules,
  getProjectFinancials,
  getSchedulePayments,
  recordPayment,
} from "@/lib/api/resources/payments";
import { getProjectContracts } from "@/lib/api/resources/contracts";
import type {
  Contract,
  Payment,
  PaymentFormInput,
  PaymentMethod,
  PaymentSchedule,
  PaymentScheduleFormInput,
  ProjectFinancials,
} from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { type Locale } from "@/lib/format/currency";
import { useMoneyFormatter } from "@/lib/format/useMoneyFormatter";
import { formatDate, formatDateTime } from "@/lib/format/date";

type T = (key: TranslationKey) => string;
type FilterKey = "all" | "overdue" | "upcoming" | "paid";

const INPUT_CLASSES =
  "w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";
const PAYMENT_METHODS: PaymentMethod[] = ["bank_transfer", "cash", "cheque"];

function todayInputValue(): string {
  return new Date().toISOString().slice(0, 10);
}

function scheduleStatusInfo(
  schedule: PaymentSchedule,
  t: T,
): { label: string; tone: "green" | "red" | "amber" } {
  if (schedule.is_paid) return { label: t("payments.status.paid"), tone: "green" };
  if (schedule.is_overdue) return { label: t("payments.status.overdue"), tone: "red" };
  return { label: t("payments.status.upcoming"), tone: "amber" };
}

function sumAmounts(payments: Payment[]): number {
  return payments.reduce((total, p) => total + Number(p.amount), 0);
}

/** Mirrors contract/page.tsx's statusLabel — the only status this frontend ever creates is
 *  "active" (contract creation always sets it), but the type allows any string. */
function contractStatusLabel(status: string, t: T): string {
  return status === "active" ? t("contract.status.active") : status;
}

export default function PaymentsPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t, locale } = useLocale();

  const [phase, setPhase] = useState<"loading" | "empty" | "ready">("loading");
  const [contract, setContract] = useState<Contract | null>(null);
  const [financials, setFinancials] = useState<ProjectFinancials | null>(null);
  const [schedules, setSchedules] = useState<PaymentSchedule[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);

  async function load() {
    setPhase("loading");
    setLoadError(null);
    try {
      const contracts = await getProjectContracts(projectId);
      if (contracts.length === 0) {
        setPhase("empty");
        return;
      }
      const activeContract = contracts[0];
      setContract(activeContract);

      const [scheduleData, financialsData] = await Promise.all([
        getPaymentSchedules(activeContract.id),
        getProjectFinancials(projectId),
      ]);
      setSchedules([...scheduleData].sort((a, b) => a.sequence_no - b.sequence_no));
      setFinancials(financialsData);
      setPhase("ready");
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
      setPhase("empty");
    }
  }

  // Intentional fetch-on-mount, matching every other screen in this app (see contract/page.tsx).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  async function refreshFinancials() {
    try {
      setFinancials(await getProjectFinancials(projectId));
    } catch {
      // Non-critical background refresh — the payment itself already succeeded and is reflected
      // in the schedule/payment lists; the summary card will simply stay stale until next load.
    }
  }

  function handleScheduleCreated(created: PaymentSchedule) {
    setSchedules((prev) => [...prev, created].sort((a, b) => a.sequence_no - b.sequence_no));
  }

  function handleScheduleUpdated(updated: PaymentSchedule) {
    setSchedules((prev) =>
      prev.map((s) => (String(s.id) === String(updated.id) ? updated : s)),
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
          {t("payments.title")}
        </h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("payments.subtitle")}</p>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {phase === "loading" ? <LoadingScreen label={t("common.loading")} /> : null}

      {phase === "empty" && !loadError ? <EmptyStateCard projectId={projectId} t={t} /> : null}

      {phase === "ready" && contract ? (
        <ReceivablesSection
          contract={contract}
          financials={financials}
          schedules={schedules}
          onScheduleCreated={handleScheduleCreated}
          onScheduleUpdated={handleScheduleUpdated}
          onPaymentRecorded={refreshFinancials}
          t={t}
          locale={locale}
        />
      ) : null}
    </div>
  );
}

function EmptyStateCard({ projectId, t }: { projectId: string; t: T }) {
  return (
    <Card>
      <CardBody className="flex flex-col items-center gap-3 py-10 text-center">
        <EmptyState message={`${t("payments.empty.title")} ${t("payments.empty.body")}`} />
        <Link
          href={`/projects/${projectId}/contract`}
          className="text-sm font-medium text-zinc-900 underline underline-offset-2 dark:text-zinc-50"
        >
          {t("payments.empty.viewContract")}
        </Link>
      </CardBody>
    </Card>
  );
}

function SummaryCard({
  contract,
  financials,
  t,
  locale,
}: {
  contract: Contract;
  financials: ProjectFinancials | null;
  t: T;
  locale: Locale;
}) {
  const { formatMoney, formatMoneyOrDash } = useMoneyFormatter();

  return (
    <Card>
      <CardBody className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
        <Field label={t("payments.summary.contractValue")} value={formatMoney(contract.contract_value)} />
        <Field
          label={t("payments.summary.collected")}
          value={formatMoneyOrDash(financials?.collected ?? null)}
        />
        <Field
          label={t("payments.summary.outstanding")}
          value={formatMoneyOrDash(financials?.outstanding ?? null)}
        />
        <Field label={t("payments.summary.status")} value={contractStatusLabel(contract.status, t)} />
      </CardBody>
    </Card>
  );
}

interface ReceivablesSectionProps {
  contract: Contract;
  financials: ProjectFinancials | null;
  schedules: PaymentSchedule[];
  onScheduleCreated: (created: PaymentSchedule) => void;
  onScheduleUpdated: (updated: PaymentSchedule) => void;
  onPaymentRecorded: () => Promise<void>;
  t: T;
  locale: Locale;
}

function ReceivablesSection({
  contract,
  financials,
  schedules,
  onScheduleCreated,
  onScheduleUpdated,
  onPaymentRecorded,
  t,
  locale,
}: ReceivablesSectionProps) {
  const { formatMoney } = useMoneyFormatter();
  const [filter, setFilter] = useState<FilterKey>("all");
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [showAddSchedule, setShowAddSchedule] = useState(false);

  const counts: Record<FilterKey, number> = {
    all: schedules.length,
    overdue: schedules.filter((s) => s.is_overdue).length,
    upcoming: schedules.filter((s) => s.is_upcoming).length,
    paid: schedules.filter((s) => s.is_paid).length,
  };

  const visibleSchedules = schedules.filter((s) => {
    if (filter === "all") return true;
    if (filter === "overdue") return s.is_overdue;
    if (filter === "upcoming") return s.is_upcoming;
    return s.is_paid;
  });

  function toggleExpanded(id: string) {
    setExpandedId((prev) => (prev === id ? null : id));
  }

  return (
    <div className="flex flex-col gap-6">
      <SummaryCard contract={contract} financials={financials} t={t} locale={locale} />

      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
          <FilterTabs filter={filter} counts={counts} onChange={setFilter} t={t} />
          <Button
            variant="secondary"
            className="py-1"
            onClick={() => setShowAddSchedule((v) => !v)}
          >
            {t("payments.schedule.add.cta")}
          </Button>
        </CardHeader>

        {showAddSchedule ? (
          <CardBody className="border-b border-zinc-200 dark:border-zinc-800">
            <AddScheduleForm
              contractId={contract.id}
              nextSequenceNo={schedules.length + 1}
              onCreated={(created) => {
                onScheduleCreated(created);
                setShowAddSchedule(false);
              }}
              onCancel={() => setShowAddSchedule(false)}
              t={t}
            />
          </CardBody>
        ) : null}

        <CardBody className="overflow-x-auto p-0">
          {visibleSchedules.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
              {schedules.length === 0 ? t("payments.schedule.empty") : t("payments.schedule.filterEmpty")}
            </p>
          ) : (
            <table className="w-full min-w-[720px] border-collapse text-sm">
              <thead>
                <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                  <th className="px-3 py-2">{t("payments.schedule.columns.name")}</th>
                  <th className="px-3 py-2">{t("payments.schedule.columns.dueDate")}</th>
                  <th className="px-3 py-2 text-right">{t("payments.schedule.columns.amount")}</th>
                  <th className="px-3 py-2">{t("payments.schedule.columns.status")}</th>
                  <th className="px-3 py-2">{t("payments.schedule.columns.actions")}</th>
                </tr>
              </thead>
              <tbody>
                {visibleSchedules.map((schedule) => {
                  const status = scheduleStatusInfo(schedule, t);
                  const isExpanded = expandedId === String(schedule.id);
                  return (
                    <Fragment key={schedule.id}>
                      <tr
                        className="border-b border-zinc-100 last:border-0 hover:bg-zinc-50 dark:border-zinc-800/60 dark:hover:bg-zinc-800/30"
                      >
                        <td className="px-3 py-2 align-top font-medium text-zinc-900 dark:text-zinc-100">
                          {schedule.name}
                        </td>
                        <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">
                          {formatDate(schedule.due_date, locale)}
                        </td>
                        <td className="px-3 py-2 text-right align-top text-zinc-900 dark:text-zinc-100">
                          {formatMoney(schedule.amount)}
                        </td>
                        <td className="px-3 py-2 align-top">
                          <Badge tone={status.tone}>{status.label}</Badge>
                        </td>
                        <td className="px-3 py-2 align-top">
                          <button
                            type="button"
                            onClick={() => toggleExpanded(String(schedule.id))}
                            className="text-xs font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100"
                          >
                            {isExpanded ? t("payments.schedule.action.close") : t("payments.schedule.action.manage")}
                          </button>
                        </td>
                      </tr>
                      {isExpanded ? (
                        <tr className="border-b border-zinc-100 dark:border-zinc-800/60">
                          <td colSpan={5} className="bg-zinc-50 px-4 py-4 dark:bg-zinc-900/40">
                            <ScheduleDetail
                              schedule={schedule}
                              onScheduleUpdated={onScheduleUpdated}
                              onPaymentRecorded={onPaymentRecorded}
                              t={t}
                              locale={locale}
                            />
                          </td>
                        </tr>
                      ) : null}
                    </Fragment>
                  );
                })}
              </tbody>
            </table>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

function FilterTabs({
  filter,
  counts,
  onChange,
  t,
}: {
  filter: FilterKey;
  counts: Record<FilterKey, number>;
  onChange: (filter: FilterKey) => void;
  t: T;
}) {
  const options: FilterKey[] = ["all", "overdue", "upcoming", "paid"];
  return (
    <div className="inline-flex flex-wrap rounded-md border border-zinc-300 p-0.5 text-sm dark:border-zinc-700">
      {options.map((option) => (
        <button
          key={option}
          type="button"
          onClick={() => onChange(option)}
          className={`rounded px-3 py-1 font-medium transition-colors ${
            filter === option
              ? "bg-amber-600 text-white dark:bg-amber-500 dark:text-zinc-950"
              : "text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
          }`}
        >
          {t(`payments.filter.${option}` as TranslationKey)} ({counts[option]})
        </button>
      ))}
    </div>
  );
}

interface AddScheduleFormProps {
  contractId: string | number;
  nextSequenceNo: number;
  onCreated: (created: PaymentSchedule) => void;
  onCancel: () => void;
  t: T;
}

/** Percentage-of-contract-value vs fixed-amount toggle, mirroring PricingPanel's ViewModeToggle
 *  pill pattern (see components/pricing/PricingPanel.tsx). Only the active mode's field is sent
 *  to the backend — the other is left undefined, matching StorePaymentScheduleRequest's "either
 *  one, not both required" rule. */
function AddScheduleForm({ contractId, nextSequenceNo, onCreated, onCancel, t }: AddScheduleFormProps) {
  const [name, setName] = useState("");
  const [sequenceNo, setSequenceNo] = useState(String(nextSequenceNo));
  const [dueDate, setDueDate] = useState("");
  const [mode, setMode] = useState<"percentage" | "amount">("percentage");
  const [percentage, setPercentage] = useState("");
  const [amount, setAmount] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const input: PaymentScheduleFormInput = {
        name,
        sequence_no: Number(sequenceNo),
        due_date: dueDate,
        ...(mode === "percentage" ? { percentage } : { amount }),
      };
      const created = await createPaymentSchedule(contractId, input);
      onCreated(created);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-4">
      <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("payments.schedule.add.title")}</p>
      <div className="flex flex-wrap items-end gap-3">
        <FormField label={t("payments.schedule.form.name")} htmlFor="schedule-name">
          <input
            id="schedule-name"
            type="text"
            required
            value={name}
            onChange={(e) => setName(e.target.value)}
            className={`${INPUT_CLASSES} w-56`}
          />
        </FormField>
        <FormField label={t("payments.schedule.form.sequenceNo")} htmlFor="schedule-sequence">
          <input
            id="schedule-sequence"
            type="number"
            min="1"
            required
            value={sequenceNo}
            onChange={(e) => setSequenceNo(e.target.value)}
            className={`${INPUT_CLASSES} w-24`}
          />
        </FormField>
        <FormField label={t("payments.schedule.form.dueDate")} htmlFor="schedule-due-date">
          <input
            id="schedule-due-date"
            type="date"
            required
            value={dueDate}
            onChange={(e) => setDueDate(e.target.value)}
            className={INPUT_CLASSES}
          />
        </FormField>
      </div>

      <div className="flex flex-col gap-2">
        <span className="text-xs font-medium text-zinc-500 dark:text-zinc-400">
          {t("payments.schedule.form.amountMode")}
        </span>
        <div className="inline-flex w-fit rounded-md border border-zinc-300 p-0.5 text-sm dark:border-zinc-700">
          <button
            type="button"
            onClick={() => setMode("percentage")}
            className={`rounded px-3 py-1 font-medium transition-colors ${
              mode === "percentage"
                ? "bg-amber-600 text-white dark:bg-amber-500 dark:text-zinc-950"
                : "text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            }`}
          >
            {t("payments.schedule.form.modePercentage")}
          </button>
          <button
            type="button"
            onClick={() => setMode("amount")}
            className={`rounded px-3 py-1 font-medium transition-colors ${
              mode === "amount"
                ? "bg-amber-600 text-white dark:bg-amber-500 dark:text-zinc-950"
                : "text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            }`}
          >
            {t("payments.schedule.form.modeAmount")}
          </button>
        </div>

        {mode === "percentage" ? (
          <FormField label={t("payments.schedule.form.percentage")} htmlFor="schedule-percentage">
            <input
              id="schedule-percentage"
              type="number"
              min="0"
              max="100"
              step="0.01"
              required
              value={percentage}
              onChange={(e) => setPercentage(e.target.value)}
              className={`${INPUT_CLASSES} w-32`}
            />
          </FormField>
        ) : (
          <FormField label={t("payments.schedule.form.amount")} htmlFor="schedule-amount">
            <input
              id="schedule-amount"
              type="number"
              min="0"
              step="0.01"
              required
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className={`${INPUT_CLASSES} w-40`}
            />
          </FormField>
        )}
      </div>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={submitting}>
          {submitting ? t("payments.schedule.form.submitting") : t("payments.schedule.form.submit")}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("common.cancel")}
        </Button>
      </div>
    </form>
  );
}

interface ScheduleDetailProps {
  schedule: PaymentSchedule;
  onScheduleUpdated: (updated: PaymentSchedule) => void;
  onPaymentRecorded: () => Promise<void>;
  t: T;
  locale: Locale;
}

/** Fetches and displays payment history for one schedule (fetched on first expand, not eagerly
 *  for every row — schedules can be numerous, payments are only needed once a row is opened),
 *  plus the Record Payment form. Computes remaining balance client-side from the fetched
 *  payments list, per PROJECT_CONTEXT.md's "check the payments list for a schedule to compute
 *  amount already paid vs schedule.amount" instruction — the backend's `status` field is the
 *  authoritative paid/pending flag, this is purely a display aid for partial payments. */
function ScheduleDetail({
  schedule,
  onScheduleUpdated,
  onPaymentRecorded,
  t,
  locale,
}: ScheduleDetailProps) {
  const { formatMoney } = useMoneyFormatter();
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  async function loadPayments() {
    setLoading(true);
    setLoadError(null);
    try {
      setPayments(await getSchedulePayments(schedule.id));
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount (this component only mounts once its row is expanded).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadPayments();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [schedule.id]);

  const paidSoFar = sumAmounts(payments);
  const remaining = Math.max(Number(schedule.amount) - paidSoFar, 0);

  /**
   * The record-payment endpoint's response is the Payment, not the updated PaymentSchedule, so
   * whether this payment completed the schedule is inferred client-side from the fresh payments
   * total vs schedule.amount — this only flips the row's badge immediately for a smoother UX; a
   * full reload of the page would confirm the same thing from the server's authoritative
   * `status` field regardless.
   */
  async function handleRecorded(payment: Payment) {
    const updatedPayments = [payment, ...payments];
    setPayments(updatedPayments);
    if (sumAmounts(updatedPayments) >= Number(schedule.amount)) {
      onScheduleUpdated({ ...schedule, status: "paid", is_paid: true, is_overdue: false, is_upcoming: false });
    }
    await onPaymentRecorded();
  }

  return (
    <div className="flex flex-col gap-4">
      {loadError ? <ErrorBanner message={loadError} onRetry={loadPayments} retryLabel={t("common.retry")} /> : null}

      {!schedule.is_paid ? (
        <div className="flex flex-wrap items-center gap-4 text-sm">
          <span className="text-zinc-600 dark:text-zinc-300">
            {t("payments.detail.paidSoFar")}: <strong className="text-zinc-900 dark:text-zinc-100">{formatMoney(paidSoFar)}</strong>
          </span>
          <span className="text-zinc-600 dark:text-zinc-300">
            {t("payments.detail.remaining")}: <strong className="text-amber-600 dark:text-amber-400">{formatMoney(remaining)}</strong>
          </span>
        </div>
      ) : (
        <p className="text-sm font-medium text-green-700 dark:text-green-400">{t("payments.detail.fullyPaid")}</p>
      )}

      {!schedule.is_paid ? (
        <RecordPaymentForm
          scheduleId={schedule.id}
          remaining={remaining}
          onRecorded={handleRecorded}
          t={t}
        />
      ) : null}

      <div>
        <p className="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-400">
          {t("payments.detail.historyTitle")}
        </p>
        {loading ? (
          <LoadingScreen label={t("common.loading")} />
        ) : payments.length === 0 ? (
          <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("payments.detail.historyEmpty")}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] border-collapse text-sm">
              <thead>
                <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                  <th className="px-2 py-2">{t("payments.detail.columns.date")}</th>
                  <th className="px-2 py-2 text-right">{t("payments.detail.columns.amount")}</th>
                  <th className="px-2 py-2">{t("payments.detail.columns.method")}</th>
                  <th className="px-2 py-2">{t("payments.detail.columns.reference")}</th>
                  <th className="px-2 py-2">{t("payments.detail.columns.notes")}</th>
                  <th className="px-2 py-2">{t("payments.detail.columns.receipt")}</th>
                </tr>
              </thead>
              <tbody>
                {payments.map((payment) => (
                  <PaymentRow key={payment.id} payment={payment} t={t} locale={locale} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

function PaymentRow({ payment, t, locale }: { payment: Payment; t: T; locale: Locale }) {
  const { formatMoney } = useMoneyFormatter();
  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  async function handleDownload() {
    setDownloading(true);
    setDownloadError(null);
    try {
      await downloadPaymentReceipt(payment.id);
    } catch {
      setDownloadError(t("payments.detail.receiptFailed"));
    } finally {
      setDownloading(false);
    }
  }

  return (
    <tr className="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
      <td className="whitespace-nowrap px-2 py-2 align-top text-zinc-600 dark:text-zinc-300">
        {formatDateTime(payment.paid_at, locale)}
      </td>
      <td className="whitespace-nowrap px-2 py-2 text-right align-top font-medium text-zinc-900 dark:text-zinc-100">
        {formatMoney(payment.amount)}
      </td>
      <td className="px-2 py-2 align-top text-zinc-600 dark:text-zinc-300">
        {t(`payments.method.${payment.payment_method}` as TranslationKey) || payment.payment_method}
      </td>
      <td className="px-2 py-2 align-top text-zinc-600 dark:text-zinc-300">{payment.reference ?? t("common.na")}</td>
      <td className="max-w-[200px] px-2 py-2 align-top text-zinc-600 dark:text-zinc-300">
        {payment.notes ?? t("common.na")}
      </td>
      <td className="px-2 py-2 align-top">
        {payment.has_receipt ? (
          <div className="flex flex-col items-start gap-1">
            <button
              type="button"
              onClick={handleDownload}
              disabled={downloading}
              className="text-xs font-medium text-zinc-500 underline underline-offset-2 hover:text-zinc-900 disabled:opacity-60 dark:hover:text-zinc-100"
            >
              {downloading ? t("payments.detail.downloading") : t("payments.detail.viewReceipt")}
            </button>
            {downloadError ? <span className="text-xs text-red-600 dark:text-red-400">{downloadError}</span> : null}
          </div>
        ) : (
          <span className="text-xs text-zinc-400">{t("common.na")}</span>
        )}
      </td>
    </tr>
  );
}

interface RecordPaymentFormProps {
  scheduleId: string | number;
  remaining: number;
  onRecorded: (payment: Payment) => Promise<void>;
  t: T;
}

function RecordPaymentForm({ scheduleId, remaining, onRecorded, t }: RecordPaymentFormProps) {
  const [amount, setAmount] = useState(remaining > 0 ? remaining.toFixed(2) : "");
  const [method, setMethod] = useState<PaymentMethod>("bank_transfer");
  const [paidAt, setPaidAt] = useState(todayInputValue());
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const [receipt, setReceipt] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const input: PaymentFormInput = {
        amount,
        payment_method: method,
        paid_at: paidAt,
        reference: reference || undefined,
        notes: notes || undefined,
        receipt,
      };
      const payment = await recordPayment(scheduleId, input);
      await onRecorded(payment);
      setAmount("");
      setReference("");
      setNotes("");
      setReceipt(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-3 rounded-md border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{t("payments.detail.recordTitle")}</p>
      <div className="flex flex-wrap items-end gap-3">
        <FormField label={t("payments.detail.form.amount")} htmlFor="payment-amount">
          <input
            id="payment-amount"
            type="number"
            min="0.01"
            step="0.01"
            required
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            className={`${INPUT_CLASSES} w-32`}
          />
        </FormField>
        <FormField label={t("payments.detail.form.method")} htmlFor="payment-method">
          <select
            id="payment-method"
            value={method}
            onChange={(e) => setMethod(e.target.value as PaymentMethod)}
            className={INPUT_CLASSES}
          >
            {PAYMENT_METHODS.map((m) => (
              <option key={m} value={m}>
                {t(`payments.method.${m}` as TranslationKey)}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={t("payments.detail.form.paidAt")} htmlFor="payment-paid-at">
          <input
            id="payment-paid-at"
            type="date"
            required
            value={paidAt}
            onChange={(e) => setPaidAt(e.target.value)}
            className={INPUT_CLASSES}
          />
        </FormField>
        <FormField label={t("payments.detail.form.reference")} htmlFor="payment-reference">
          <input
            id="payment-reference"
            type="text"
            value={reference}
            onChange={(e) => setReference(e.target.value)}
            className={`${INPUT_CLASSES} w-40`}
          />
        </FormField>
      </div>
      <FormField label={t("payments.detail.form.notes")} htmlFor="payment-notes">
        <textarea
          id="payment-notes"
          rows={2}
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          className={`${INPUT_CLASSES} resize-y`}
        />
      </FormField>
      <FormField label={t("payments.detail.form.receipt")} htmlFor="payment-receipt">
        <input
          id="payment-receipt"
          type="file"
          accept="image/jpeg,image/png,application/pdf"
          onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
          className="text-sm text-zinc-600 dark:text-zinc-300"
        />
      </FormField>

      {error ? <ErrorBanner message={error} /> : null}

      <div>
        <Button type="submit" disabled={submitting} className="py-1.5">
          {submitting ? t("payments.detail.form.submitting") : t("payments.detail.form.submit")}
        </Button>
      </div>
    </form>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{label}</p>
      <p className="mt-0.5 text-zinc-900 dark:text-zinc-100">{value}</p>
    </div>
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
