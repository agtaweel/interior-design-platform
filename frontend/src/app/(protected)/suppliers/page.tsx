"use client";

/**
 * Suppliers directory (BRD "Procurement & Supplier Intelligence"). Org-wide, not project-
 * scoped — a supplier is reused across every project (see backend Supplier model docblock).
 * Each row expands to show its price history (BRD "maintain supplier/product price history
 * with date and source"), auto-recorded whenever a purchase order is sent/received — there is
 * no manual price-history entry screen (BRD explicitly allows this for MVP: "MVP should first
 * capture clean historical data").
 */

import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import {
  createSupplier,
  getSupplierPriceHistory,
  getSuppliers,
} from "@/lib/api/resources/suppliers";
import type { Supplier, SupplierFormInput, SupplierPriceHistoryEntry } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
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

export default function SuppliersPage() {
  const { t, locale } = useLocale();
  const [suppliers, setSuppliers] = useState<Supplier[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [showCreate, setShowCreate] = useState(false);
  const [expandedId, setExpandedId] = useState<string | null>(null);

  async function load() {
    setLoadError(null);
    setForbidden(false);
    try {
      setSuppliers(await getSuppliers());
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) {
        setForbidden(true);
      } else {
        setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
      }
    }
  }

  // Intentional fetch-on-mount, matching every other screen in this app.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, []);

  function handleCreated(created: Supplier) {
    setSuppliers((prev) => [created, ...(prev ?? [])]);
    setShowCreate(false);
  }

  if (forbidden) {
    return (
      <div className="flex flex-col gap-6">
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("suppliers.title")}</h1>
        <ErrorBanner message={t("suppliers.forbidden")} />
      </div>
    );
  }

  if (suppliers === null && !loadError) {
    return <LoadingScreen label={t("common.loading")} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("suppliers.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("suppliers.subtitle")}</p>
        </div>
        <Button type="button" onClick={() => setShowCreate((v) => !v)}>
          {t("suppliers.create.cta")}
        </Button>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {showCreate ? (
        <Card>
          <CardBody>
            <CreateSupplierForm onCreated={handleCreated} onCancel={() => setShowCreate(false)} t={t} />
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardBody className="p-0">
          {suppliers && suppliers.length === 0 ? (
            <EmptyState message={t("suppliers.empty")} />
          ) : (
            <table className="w-full min-w-[720px] border-collapse text-sm">
              <thead>
                <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                  <th className="px-3 py-2">{t("suppliers.columns.name")}</th>
                  <th className="px-3 py-2">{t("suppliers.columns.category")}</th>
                  <th className="px-3 py-2">{t("suppliers.columns.contact")}</th>
                  <th className="px-3 py-2">{t("suppliers.columns.paymentTerms")}</th>
                  <th className="px-3 py-2">{t("suppliers.columns.actions")}</th>
                </tr>
              </thead>
              <tbody>
                {(suppliers ?? []).map((supplier) => (
                  <SupplierRow
                    key={supplier.id}
                    supplier={supplier}
                    expanded={expandedId === String(supplier.id)}
                    onToggle={() =>
                      setExpandedId((prev) => (prev === String(supplier.id) ? null : String(supplier.id)))
                    }
                    t={t}
                    locale={locale}
                  />
                ))}
              </tbody>
            </table>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

function SupplierRow({
  supplier,
  expanded,
  onToggle,
  t,
  locale,
}: {
  supplier: Supplier;
  expanded: boolean;
  onToggle: () => void;
  t: T;
  locale: "en" | "ar";
}) {
  return (
    <>
      <tr className="border-b border-zinc-100 last:border-0 hover:bg-zinc-50 dark:border-zinc-800/60 dark:hover:bg-zinc-800/30">
        <td className="px-3 py-2 align-top font-medium text-zinc-900 dark:text-zinc-100">{supplier.name}</td>
        <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">{supplier.category ?? t("common.na")}</td>
        <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">
          {supplier.contact_name ?? t("common.na")}
          {supplier.phone ? ` · ${supplier.phone}` : ""}
        </td>
        <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">{supplier.payment_terms ?? t("common.na")}</td>
        <td className="px-3 py-2 align-top">
          <button
            type="button"
            onClick={onToggle}
            className="text-xs font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100"
          >
            {expanded ? t("suppliers.action.hideHistory") : t("suppliers.action.viewHistory")}
          </button>
        </td>
      </tr>
      {expanded ? (
        <tr className="border-b border-zinc-100 dark:border-zinc-800/60">
          <td colSpan={5} className="bg-zinc-50 px-4 py-4 dark:bg-zinc-900/40">
            <PriceHistory supplierId={supplier.id} t={t} locale={locale} />
          </td>
        </tr>
      ) : null}
    </>
  );
}

function PriceHistory({ supplierId, t, locale }: { supplierId: Supplier["id"]; t: T; locale: "en" | "ar" }) {
  const { formatMoney } = useMoneyFormatter();
  const [history, setHistory] = useState<SupplierPriceHistoryEntry[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    getSupplierPriceHistory(supplierId)
      .then((data) => {
        if (!cancelled) setHistory(data);
      })
      .catch(() => {
        if (!cancelled) setError(t("common.unknownError"));
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [supplierId]);

  if (error) return <ErrorBanner message={error} />;
  if (history === null) return <LoadingScreen label={t("common.loading")} />;
  if (history.length === 0) {
    return <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("suppliers.history.empty")}</p>;
  }

  return (
    <div className="flex flex-col gap-1">
      <p className="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-400">{t("suppliers.history.title")}</p>
      <table className="w-full text-xs">
        <thead>
          <tr className="text-left text-zinc-400">
            <th className="py-1 pe-3">{t("suppliers.history.item")}</th>
            <th className="py-1 pe-3">{t("suppliers.history.price")}</th>
            <th className="py-1 pe-3">{t("suppliers.history.source")}</th>
            <th className="py-1">{t("suppliers.history.date")}</th>
          </tr>
        </thead>
        <tbody>
          {history.map((entry) => (
            <tr key={entry.id} className="text-zinc-700 dark:text-zinc-200">
              <td className="py-1 pe-3">{entry.item_description} ({entry.unit})</td>
              <td className="py-1 pe-3">{formatMoney(entry.unit_price)}</td>
              <td className="py-1 pe-3">{t(`suppliers.history.source.${entry.source}` as TranslationKey)}</td>
              <td className="py-1">{formatDate(entry.recorded_at, locale)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function CreateSupplierForm({
  onCreated,
  onCancel,
  t,
}: {
  onCreated: (created: Supplier) => void;
  onCancel: () => void;
  t: T;
}) {
  const [name, setName] = useState("");
  const [category, setCategory] = useState("");
  const [contactName, setContactName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [paymentTerms, setPaymentTerms] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const input: SupplierFormInput = {
        name,
        category: category || undefined,
        contact_name: contactName || undefined,
        phone: phone || undefined,
        email: email || undefined,
        payment_terms: paymentTerms || undefined,
      };
      onCreated(await createSupplier(input));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-3">
      <p className="text-sm font-medium text-zinc-700 dark:text-zinc-200">{t("suppliers.create.title")}</p>
      <div className="flex flex-wrap items-end gap-3">
        <Field label={t("suppliers.create.name")} htmlFor="supplier-name">
          <input id="supplier-name" required value={name} onChange={(e) => setName(e.target.value)} className={`${INPUT_CLASSES} w-56`} />
        </Field>
        <Field label={t("suppliers.create.category")} htmlFor="supplier-category">
          <input id="supplier-category" value={category} onChange={(e) => setCategory(e.target.value)} className={`${INPUT_CLASSES} w-40`} />
        </Field>
        <Field label={t("suppliers.create.contactName")} htmlFor="supplier-contact">
          <input id="supplier-contact" value={contactName} onChange={(e) => setContactName(e.target.value)} className={`${INPUT_CLASSES} w-48`} />
        </Field>
        <Field label={t("suppliers.create.phone")} htmlFor="supplier-phone">
          <input id="supplier-phone" value={phone} onChange={(e) => setPhone(e.target.value)} className={`${INPUT_CLASSES} w-40`} />
        </Field>
        <Field label={t("suppliers.create.email")} htmlFor="supplier-email">
          <input id="supplier-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} className={`${INPUT_CLASSES} w-56`} />
        </Field>
        <Field label={t("suppliers.create.paymentTerms")} htmlFor="supplier-terms">
          <input id="supplier-terms" value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value)} className={`${INPUT_CLASSES} w-36`} />
        </Field>
      </div>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="flex items-center gap-3">
        <Button type="submit" disabled={submitting}>
          {submitting ? t("suppliers.create.submitting") : t("suppliers.create.submit")}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("common.cancel")}
        </Button>
      </div>
    </form>
  );
}

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={htmlFor} className="text-xs font-medium text-zinc-500 dark:text-zinc-400">
        {label}
      </label>
      {children}
    </div>
  );
}
