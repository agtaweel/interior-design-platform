"use client";

/**
 * Expenses tab — BRD S18 "Expenses/Suppliers: actual cost capture". Purchase orders and
 * expenses live together on one screen (matching that screen's own bundled title) — both feed
 * ProjectCostCalculator's actual_cost figure (see Overview tab's financials card).
 */

import { useEffect, useState, type FormEvent } from "react";
import { useParams } from "next/navigation";
import {
  cancelPurchaseOrder,
  createPurchaseOrder,
  getPurchaseOrders,
  receivePurchaseOrder,
  sendPurchaseOrder,
} from "@/lib/api/resources/purchaseOrders";
import { getSuppliers } from "@/lib/api/resources/suppliers";
import { createExpense, downloadExpenseReceipt, getExpenses } from "@/lib/api/resources/expenses";
import type {
  Expense,
  PurchaseOrder,
  PurchaseOrderItemInput,
  Supplier,
} from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { useMoneyFormatter } from "@/lib/format/useMoneyFormatter";
import { formatDate } from "@/lib/format/date";

type T = (key: TranslationKey) => string;
const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

export default function ExpensesPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t } = useLocale();

  return (
    <div className="flex flex-col gap-8">
      <PurchaseOrdersSection projectId={projectId} t={t} />
      <ExpensesSection projectId={projectId} t={t} />
    </div>
  );
}

function PurchaseOrdersSection({ projectId, t }: { projectId: string; t: T }) {
  const { locale } = useLocale();
  const [orders, setOrders] = useState<PurchaseOrder[] | null>(null);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);

  async function load() {
    setLoadError(null);
    try {
      const [orderData, supplierData] = await Promise.all([getPurchaseOrders(projectId), getSuppliers()]);
      setOrders(orderData);
      setSuppliers(supplierData);
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  function handleCreated(created: PurchaseOrder) {
    setOrders((prev) => [created, ...(prev ?? [])]);
    setShowCreate(false);
  }

  function handleUpdated(updated: PurchaseOrder) {
    setOrders((prev) => (prev ?? []).map((o) => (String(o.id) === String(updated.id) ? updated : o)));
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("expenses.po.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("expenses.po.subtitle")}</p>
        </div>
        <Button type="button" onClick={() => setShowCreate((v) => !v)} disabled={suppliers.length === 0}>
          {t("expenses.po.create.cta")}
        </Button>
      </div>

      {suppliers.length === 0 && orders !== null ? (
        <p className="text-xs text-zinc-500 dark:text-zinc-400">{t("expenses.po.noSuppliers")}</p>
      ) : null}

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {showCreate ? (
        <Card>
          <CardBody>
            <CreatePoForm
              projectId={projectId}
              suppliers={suppliers}
              onCreated={handleCreated}
              onCancel={() => setShowCreate(false)}
              t={t}
            />
          </CardBody>
        </Card>
      ) : null}

      {orders === null ? (
        <LoadingScreen label={t("common.loading")} />
      ) : orders.length === 0 ? (
        <EmptyState message={t("expenses.po.empty")} />
      ) : (
        <div className="flex flex-col gap-3">
          {orders.map((order) => (
            <PoCard key={order.id} order={order} onUpdated={handleUpdated} t={t} locale={locale} />
          ))}
        </div>
      )}
    </div>
  );
}

function statusTone(status: PurchaseOrder["status"]): "green" | "red" | "amber" {
  if (status === "received") return "green";
  if (status === "cancelled") return "red";
  return "amber";
}

function PoCard({
  order,
  onUpdated,
  t,
  locale,
}: {
  order: PurchaseOrder;
  onUpdated: (updated: PurchaseOrder) => void;
  t: T;
  locale: "en" | "ar";
}) {
  const { formatMoney } = useMoneyFormatter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [receiveOpen, setReceiveOpen] = useState(false);

  async function handleSend() {
    setBusy(true);
    setError(null);
    try {
      onUpdated(await sendPurchaseOrder(order.id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setBusy(false);
    }
  }

  async function handleCancel() {
    if (!window.confirm(t("expenses.po.cancelConfirm"))) return;
    setBusy(true);
    setError(null);
    try {
      onUpdated(await cancelPurchaseOrder(order.id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setBusy(false);
    }
  }

  const quotedTotal = order.items.reduce((sum, item) => sum + Number(item.quoted_total), 0);

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-zinc-900 dark:text-zinc-100">
            {order.po_number} — {order.supplier.name}
          </p>
          <p className="text-xs text-zinc-500 dark:text-zinc-400">{formatMoney(quotedTotal)}</p>
        </div>
        <Badge tone={statusTone(order.status)}>{t(`expenses.po.status.${order.status}` as TranslationKey)}</Badge>
      </CardHeader>
      <CardBody className="flex flex-col gap-3">
        <table className="w-full text-xs">
          <thead>
            <tr className="text-left text-zinc-400">
              <th className="py-1 pe-3">{t("expenses.po.item.description")}</th>
              <th className="py-1 pe-3">{t("expenses.po.item.quantity")}</th>
              <th className="py-1 pe-3">{t("expenses.po.item.quoted")}</th>
              <th className="py-1 pe-3">{t("expenses.po.item.received")}</th>
              <th className="py-1">{t("expenses.po.item.actual")}</th>
            </tr>
          </thead>
          <tbody>
            {order.items.map((item) => (
              <tr key={item.id} className="text-zinc-700 dark:text-zinc-200">
                <td className="py-1 pe-3">{item.description} ({item.unit})</td>
                <td className="py-1 pe-3">{item.quantity}</td>
                <td className="py-1 pe-3">{formatMoney(item.quoted_unit_price)}</td>
                <td className="py-1 pe-3">{item.received_quantity}</td>
                <td className="py-1">{item.actual_unit_price !== null ? formatMoney(item.actual_unit_price) : t("common.na")}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {error ? <ErrorBanner message={error} /> : null}

        <div className="flex flex-wrap items-center gap-3">
          {order.status === "draft" ? (
            <Button type="button" className="py-1" onClick={handleSend} disabled={busy}>
              {t("expenses.po.action.send")}
            </Button>
          ) : null}
          {(order.status === "sent" || order.status === "partially_received") ? (
            <Button type="button" variant="secondary" className="py-1" onClick={() => setReceiveOpen((v) => !v)}>
              {t("expenses.po.action.receive")}
            </Button>
          ) : null}
          {order.status !== "received" && order.status !== "cancelled" ? (
            <button
              type="button"
              onClick={handleCancel}
              disabled={busy}
              className="text-xs font-medium text-red-600 hover:underline disabled:opacity-60 dark:text-red-400"
            >
              {t("expenses.po.action.cancel")}
            </button>
          ) : null}
        </div>

        {receiveOpen ? (
          <ReceiveForm order={order} onReceived={(updated) => { onUpdated(updated); setReceiveOpen(false); }} t={t} />
        ) : null}
      </CardBody>
    </Card>
  );
}

function ReceiveForm({
  order,
  onReceived,
  t,
}: {
  order: PurchaseOrder;
  onReceived: (updated: PurchaseOrder) => void;
  t: T;
}) {
  const pendingItems = order.items.filter((item) => Number(item.received_quantity) < Number(item.quantity));
  const [values, setValues] = useState<Record<string, { qty: string; price: string }>>(
    Object.fromEntries(
      pendingItems.map((item) => [
        String(item.id),
        { qty: String(Number(item.quantity) - Number(item.received_quantity)), price: String(item.quoted_unit_price) },
      ]),
    ),
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const lines = pendingItems
        .map((item) => ({
          id: item.id,
          received_quantity: values[String(item.id)]?.qty ?? "0",
          actual_unit_price: values[String(item.id)]?.price ?? "0",
        }))
        .filter((line) => Number(line.received_quantity) > 0);
      const updated = await receivePurchaseOrder(order.id, lines);
      onReceived(updated);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-2 rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{t("expenses.po.receive.title")}</p>
      {pendingItems.map((item) => (
        <div key={item.id} className="flex flex-wrap items-end gap-3">
          <span className="min-w-[140px] text-sm text-zinc-700 dark:text-zinc-200">{item.description}</span>
          <div className="flex flex-col gap-1">
            <label className="text-xs text-zinc-500 dark:text-zinc-400">{t("expenses.po.item.received")}</label>
            <input
              type="number"
              step="0.001"
              min="0"
              value={values[String(item.id)]?.qty ?? ""}
              onChange={(e) => setValues((prev) => ({ ...prev, [String(item.id)]: { ...prev[String(item.id)], qty: e.target.value } }))}
              className={`${INPUT_CLASSES} w-28`}
            />
          </div>
          <div className="flex flex-col gap-1">
            <label className="text-xs text-zinc-500 dark:text-zinc-400">{t("expenses.po.item.actual")}</label>
            <input
              type="number"
              step="0.01"
              min="0"
              value={values[String(item.id)]?.price ?? ""}
              onChange={(e) => setValues((prev) => ({ ...prev, [String(item.id)]: { ...prev[String(item.id)], price: e.target.value } }))}
              className={`${INPUT_CLASSES} w-28`}
            />
          </div>
        </div>
      ))}
      {error ? <ErrorBanner message={error} /> : null}
      <div>
        <Button type="submit" className="py-1" disabled={submitting}>
          {submitting ? t("expenses.po.receive.submitting") : t("expenses.po.receive.submit")}
        </Button>
      </div>
    </form>
  );
}

function CreatePoForm({
  projectId,
  suppliers,
  onCreated,
  onCancel,
  t,
}: {
  projectId: string;
  suppliers: Supplier[];
  onCreated: (created: PurchaseOrder) => void;
  onCancel: () => void;
  t: T;
}) {
  const [supplierId, setSupplierId] = useState(suppliers[0]?.id ?? "");
  const [items, setItems] = useState<PurchaseOrderItemInput[]>([
    { description: "", unit: "", quantity: "", quoted_unit_price: "" },
  ]);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function updateItem(index: number, field: keyof PurchaseOrderItemInput, value: string) {
    setItems((prev) => prev.map((item, i) => (i === index ? { ...item, [field]: value } : item)));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const created = await createPurchaseOrder(projectId, { supplier_id: supplierId, items });
      onCreated(created);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-3">
      <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("expenses.po.create.title")}</p>
      <div className="flex flex-col gap-1">
        <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("expenses.po.create.supplier")}</label>
        <select value={supplierId} onChange={(e) => setSupplierId(e.target.value)} className={`${INPUT_CLASSES} w-64`}>
          {suppliers.map((supplier) => (
            <option key={supplier.id} value={supplier.id}>
              {supplier.name}
            </option>
          ))}
        </select>
      </div>

      {items.map((item, index) => (
        <div key={index} className="flex flex-wrap items-end gap-3">
          <input
            placeholder={t("expenses.po.item.description")}
            required
            value={item.description}
            onChange={(e) => updateItem(index, "description", e.target.value)}
            className={`${INPUT_CLASSES} w-48`}
          />
          <input
            placeholder={t("expenses.po.item.unit")}
            required
            value={item.unit}
            onChange={(e) => updateItem(index, "unit", e.target.value)}
            className={`${INPUT_CLASSES} w-24`}
          />
          <input
            type="number"
            step="0.001"
            min="0.001"
            placeholder={t("expenses.po.item.quantity")}
            required
            value={item.quantity}
            onChange={(e) => updateItem(index, "quantity", e.target.value)}
            className={`${INPUT_CLASSES} w-28`}
          />
          <input
            type="number"
            step="0.01"
            min="0"
            placeholder={t("expenses.po.item.quoted")}
            required
            value={item.quoted_unit_price}
            onChange={(e) => updateItem(index, "quoted_unit_price", e.target.value)}
            className={`${INPUT_CLASSES} w-28`}
          />
        </div>
      ))}
      <button
        type="button"
        onClick={() => setItems((prev) => [...prev, { description: "", unit: "", quantity: "", quoted_unit_price: "" }])}
        className="w-fit text-xs font-medium text-zinc-500 underline underline-offset-2 hover:text-zinc-900 dark:hover:text-zinc-100"
      >
        {t("expenses.po.create.addItem")}
      </button>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={submitting}>
          {submitting ? t("expenses.po.create.submitting") : t("expenses.po.create.submit")}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("common.cancel")}
        </Button>
      </div>
    </form>
  );
}

function ExpensesSection({ projectId, t }: { projectId: string; t: T }) {
  const { locale } = useLocale();
  const [expenses, setExpenses] = useState<Expense[] | null>(null);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);

  async function load() {
    setLoadError(null);
    try {
      const [expenseData, supplierData] = await Promise.all([getExpenses(projectId), getSuppliers().catch(() => [])]);
      setExpenses(expenseData);
      setSuppliers(supplierData);
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  function handleCreated(created: Expense) {
    setExpenses((prev) => [created, ...(prev ?? [])]);
    setShowCreate(false);
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">{t("expenses.title")}</h2>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("expenses.subtitle")}</p>
        </div>
        <Button type="button" onClick={() => setShowCreate((v) => !v)}>
          {t("expenses.create.cta")}
        </Button>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {showCreate ? (
        <Card>
          <CardBody>
            <CreateExpenseForm projectId={projectId} suppliers={suppliers} onCreated={handleCreated} onCancel={() => setShowCreate(false)} t={t} />
          </CardBody>
        </Card>
      ) : null}

      {expenses === null ? (
        <LoadingScreen label={t("common.loading")} />
      ) : expenses.length === 0 ? (
        <EmptyState message={t("expenses.empty")} />
      ) : (
        <Card>
          <CardBody className="overflow-x-auto p-0">
            <table className="w-full min-w-[640px] border-collapse text-sm">
              <thead>
                <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                  <th className="px-3 py-2">{t("expenses.columns.date")}</th>
                  <th className="px-3 py-2">{t("expenses.columns.category")}</th>
                  <th className="px-3 py-2">{t("expenses.columns.description")}</th>
                  <th className="px-3 py-2">{t("expenses.columns.supplier")}</th>
                  <th className="px-3 py-2 text-right">{t("expenses.columns.amount")}</th>
                  <th className="px-3 py-2">{t("expenses.columns.receipt")}</th>
                </tr>
              </thead>
              <tbody>
                {expenses.map((expense) => (
                  <ExpenseRow key={expense.id} expense={expense} t={t} locale={locale} />
                ))}
              </tbody>
            </table>
          </CardBody>
        </Card>
      )}
    </div>
  );
}

function ExpenseRow({ expense, t, locale }: { expense: Expense; t: T; locale: "en" | "ar" }) {
  const { formatMoney } = useMoneyFormatter();
  const [downloading, setDownloading] = useState(false);

  async function handleDownload() {
    setDownloading(true);
    try {
      await downloadExpenseReceipt(expense.id);
    } finally {
      setDownloading(false);
    }
  }

  return (
    <tr className="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
      <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">{formatDate(expense.expense_date, locale)}</td>
      <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">{expense.category}</td>
      <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">{expense.description}</td>
      <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">{expense.supplier?.name ?? t("common.na")}</td>
      <td className="px-3 py-2 text-right align-top font-medium text-zinc-900 dark:text-zinc-100">{formatMoney(expense.amount)}</td>
      <td className="px-3 py-2 align-top">
        {expense.has_receipt ? (
          <button
            type="button"
            onClick={handleDownload}
            disabled={downloading}
            className="text-xs font-medium text-zinc-500 underline underline-offset-2 hover:text-zinc-900 disabled:opacity-60 dark:hover:text-zinc-100"
          >
            {downloading ? t("expenses.detail.downloading") : t("expenses.detail.viewReceipt")}
          </button>
        ) : (
          <span className="text-xs text-zinc-400">{t("common.na")}</span>
        )}
      </td>
    </tr>
  );
}

function CreateExpenseForm({
  projectId,
  suppliers,
  onCreated,
  onCancel,
  t,
}: {
  projectId: string;
  suppliers: Supplier[];
  onCreated: (created: Expense) => void;
  onCancel: () => void;
  t: T;
}) {
  const [category, setCategory] = useState("material");
  const [description, setDescription] = useState("");
  const [amount, setAmount] = useState("");
  const [expenseDate, setExpenseDate] = useState(new Date().toISOString().slice(0, 10));
  const [supplierId, setSupplierId] = useState("");
  const [receipt, setReceipt] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const created = await createExpense(projectId, {
        category,
        description,
        amount,
        expense_date: expenseDate,
        supplier_id: supplierId || undefined,
        receipt,
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
      <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("expenses.create.title")}</p>
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("expenses.create.category")}</label>
          <select value={category} onChange={(e) => setCategory(e.target.value)} className={`${INPUT_CLASSES} w-36`}>
            <option value="material">{t("expenses.category.material")}</option>
            <option value="labor">{t("expenses.category.labor")}</option>
            <option value="subcontractor">{t("expenses.category.subcontractor")}</option>
            <option value="other">{t("expenses.category.other")}</option>
          </select>
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("expenses.create.description")}</label>
          <input required value={description} onChange={(e) => setDescription(e.target.value)} className={`${INPUT_CLASSES} w-56`} />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("expenses.create.amount")}</label>
          <input type="number" step="0.01" min="0.01" required value={amount} onChange={(e) => setAmount(e.target.value)} className={`${INPUT_CLASSES} w-32`} />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("expenses.create.date")}</label>
          <input type="date" required value={expenseDate} onChange={(e) => setExpenseDate(e.target.value)} className={INPUT_CLASSES} />
        </div>
        {suppliers.length > 0 ? (
          <div className="flex flex-col gap-1">
            <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("expenses.create.supplier")}</label>
            <select value={supplierId} onChange={(e) => setSupplierId(e.target.value)} className={`${INPUT_CLASSES} w-48`}>
              <option value="">{t("common.na")}</option>
              {suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
                </option>
              ))}
            </select>
          </div>
        ) : null}
        <div className="flex flex-col gap-1">
          <label className="text-xs font-medium text-zinc-500 dark:text-zinc-400">{t("expenses.create.receipt")}</label>
          <input type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => setReceipt(e.target.files?.[0] ?? null)} className="text-sm text-zinc-600 dark:text-zinc-300" />
        </div>
      </div>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={submitting}>
          {submitting ? t("expenses.create.submitting") : t("expenses.create.submit")}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("common.cancel")}
        </Button>
      </div>
    </form>
  );
}
