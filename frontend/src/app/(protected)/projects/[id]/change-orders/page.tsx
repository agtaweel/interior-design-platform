"use client";

/**
 * S14 — Change Orders (PROJECT_CONTEXT.md Sprint 7). Four-verb lifecycle: draft -> sent ->
 * approved|rejected -> applied. `apply` is a separate staff-triggered step from the client's
 * public `approve` (approving never touches the BOQ/contract by itself — see
 * ChangeOrderApplyService's docblock) — this screen only ever calls apply from an internal
 * button, gated on status === 'approved'.
 *
 * Layout mirrors proposal/page.tsx's version-history + single-panel pattern: a left-hand list
 * of change orders drives which one's detail is shown on the right. Unlike proposals (which
 * always have a server-created draft to edit), a change order's `reason` + at least one `items`
 * row must be supplied up front (StoreChangeOrderRequest requires both), so "New Change Order"
 * opens a client-side-only blank form first — POST only fires once the user hits "Create
 * Draft" — rather than creating an empty draft server-side immediately like proposals do.
 *
 * Immutability: a change order is only editable (PATCH) while status === 'draft' (409
 * CHANGE_ORDER_NOT_EDITABLE otherwise, verified against ChangeOrderController's docblock) —
 * this screen never even renders an editable form for a non-draft change order; once locked,
 * the panel shows a read-only view plus a "Create New" action instead, per this screen's spec.
 */

import { useEffect, useMemo, useState, type ReactNode } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import {
  applyChangeOrder,
  createChangeOrder,
  getChangeOrder,
  getProjectChangeOrders,
  sendChangeOrder,
  updateChangeOrder,
} from "@/lib/api/resources/changeOrders";
import { getProjectBoq } from "@/lib/api/resources/boq";
import type {
  BoqCategoryNode,
  ChangeOrder,
  ChangeOrderItem,
  ChangeOrderItemAction,
  ChangeOrderItemInput,
  ChangeOrderSendResult,
  ChangeOrderStatus,
  ChangeOrderSummary,
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
import { formatDateTime } from "@/lib/format/date";

type T = (key: TranslationKey) => string;

const STATUS_TONE: Record<ChangeOrderStatus, "neutral" | "blue" | "green" | "amber" | "red"> = {
  draft: "neutral",
  sent: "blue",
  approved: "green",
  rejected: "red",
  applied: "amber",
};

const INPUT_CLASSES =
  "w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";
const TEXTAREA_CLASSES = `${INPUT_CLASSES} resize-y`;

/** Flattened BOQ item for the remove/modify picker (see file docblock — the picker is sourced
 *  from GET /projects/{id}/boq's category tree, flattened here since the picker itself doesn't
 *  need the tree structure, just a searchable flat list with a category breadcrumb for
 *  context). */
interface FlatBoqItem {
  id: string;
  name: string;
  unit: string;
  quantity: string;
  clientUnitPrice: string;
  categoryPath: string;
}

function flattenBoq(categories: BoqCategoryNode[], path: string[] = []): FlatBoqItem[] {
  const out: FlatBoqItem[] = [];
  for (const category of categories) {
    const nextPath = [...path, category.name];
    for (const item of category.items) {
      out.push({
        id: String(item.id),
        name: item.name,
        unit: item.unit,
        quantity: String(item.quantity),
        clientUnitPrice: String(item.client_unit_price),
        categoryPath: nextPath.join(" / "),
      });
    }
    out.push(...flattenBoq(category.children, nextPath));
  }
  return out;
}

/** One item row in the editor's local form state — always guaranteed plain strings (never
 *  null/undefined), unlike ChangeOrderItem itself which mirrors the server's nullable columns.
 *  `key` is a client-only identity for React lists / row removal, never sent to the server. */
interface ItemFormRow {
  key: string;
  action: ChangeOrderItemAction;
  boq_item_id: string;
  description: string;
  quantity: string;
  unit: string;
  old_unit_price: string;
  new_unit_price: string;
}

let rowKeySeq = 0;
function newRowKey(): string {
  rowKeySeq += 1;
  return `row-${rowKeySeq}`;
}

function emptyRow(): ItemFormRow {
  return {
    key: newRowKey(),
    action: "add",
    boq_item_id: "",
    description: "",
    quantity: "",
    unit: "",
    old_unit_price: "",
    new_unit_price: "",
  };
}

function rowFromItem(item: ChangeOrderItem): ItemFormRow {
  return {
    key: newRowKey(),
    action: item.action,
    boq_item_id: item.boq_item_id !== null ? String(item.boq_item_id) : "",
    description: item.description,
    quantity: String(item.quantity),
    unit: item.unit,
    old_unit_price: item.old_unit_price !== null ? String(item.old_unit_price) : "",
    new_unit_price: item.new_unit_price !== null ? String(item.new_unit_price) : "",
  };
}

/** Mirrors ChangeOrderService::computeLineDelta() exactly (add: qty*new; remove: -(qty*old);
 *  modify: qty*(new-old)), falling back to the picked BOQ item's current client_unit_price when
 *  old_unit_price is left blank — same fallback ChangeOrderService::resolveOldUnitPrice() does
 *  server-side — so this preview matches what will actually be persisted once saved. Preview
 *  only: the server is always the source of truth for the saved price_delta. */
function previewLineDelta(row: ItemFormRow, boqById: Map<string, FlatBoqItem>): number {
  const qty = Number(row.quantity);
  if (!Number.isFinite(qty)) return 0;

  function resolveOld(): number {
    if (row.old_unit_price.trim() !== "") {
      const n = Number(row.old_unit_price);
      return Number.isFinite(n) ? n : 0;
    }
    const boqItem = boqById.get(row.boq_item_id);
    return boqItem ? Number(boqItem.clientUnitPrice) : 0;
  }

  if (row.action === "add") {
    const n = Number(row.new_unit_price);
    return Number.isFinite(n) ? qty * n : 0;
  }
  if (row.action === "remove") {
    return -(qty * resolveOld());
  }
  const n = Number(row.new_unit_price);
  const newPrice = Number.isFinite(n) ? n : 0;
  return qty * (newPrice - resolveOld());
}

function rowIsValid(row: ItemFormRow): boolean {
  if (!row.description.trim() || !row.unit.trim()) return false;
  const qty = Number(row.quantity);
  if (!Number.isFinite(qty) || qty <= 0) return false;
  if (row.action !== "add" && !row.boq_item_id) return false;
  if (row.action !== "remove") {
    const n = Number(row.new_unit_price);
    if (!Number.isFinite(n) || n < 0) return false;
  }
  return true;
}

/**
 * old_unit_price is NEVER sent — the backend rejects it outright for every action
 * (StoreChangeOrderRequest/UpdateChangeOrderRequest: `'items.*.old_unit_price' =>
 * ['prohibited']`) because it's commercially load-bearing (feeds line_delta -> price_delta ->
 * contracts.contract_value on apply) and must always be derived server-side from the live BOQ
 * item, never client-supplied. The form's old_unit_price field (see previewLineDelta()) is
 * preview-only, exactly as already documented above.
 */
function rowToInput(row: ItemFormRow): ChangeOrderItemInput {
  const input: ChangeOrderItemInput = {
    action: row.action,
    description: row.description.trim(),
    quantity: Number(row.quantity),
    unit: row.unit.trim(),
  };
  if (row.action !== "add") {
    input.boq_item_id = Number(row.boq_item_id);
  }
  if (row.action !== "remove") {
    input.new_unit_price = Number(row.new_unit_price);
  }
  return input;
}

/** Comparable snapshot of the editable form state, used only to detect "unsaved changes"
 *  (`row.key` deliberately excluded — it's a client-only React identity, not saved data). */
function snapshotKey(reason: string, timelineDeltaDays: string, rows: ItemFormRow[]): string {
  return JSON.stringify({
    reason: reason.trim(),
    timeline_delta_days: timelineDeltaDays.trim() === "" ? null : Number(timelineDeltaDays),
    items: rows.map((r) => ({
      action: r.action,
      boq_item_id: r.action === "add" ? null : r.boq_item_id || null,
      description: r.description.trim(),
      quantity: r.quantity,
      unit: r.unit.trim(),
      old_unit_price: r.action === "add" ? null : r.old_unit_price.trim() || null,
      new_unit_price: r.action === "remove" ? null : r.new_unit_price,
    })),
  });
}

function formatTimelineDelta(days: number | null, t: T): string {
  if (days === null || days === undefined) return t("changeOrders.detail.timelineDeltaNone");
  const sign = days > 0 ? "+" : "";
  return `${sign}${days} ${t("changeOrders.detail.timelineDeltaUnit")}`;
}

function milestoneLine(s: ChangeOrderSummary, t: T, locale: Locale): string {
  if (s.applied_at) return `${t("changeOrders.detail.appliedAt")}: ${formatDateTime(s.applied_at, locale)}`;
  if (s.approved_at) return `${t("changeOrders.detail.approvedAt")}: ${formatDateTime(s.approved_at, locale)}`;
  if (s.sent_at) return `${t("changeOrders.detail.sentAt")}: ${formatDateTime(s.sent_at, locale)}`;
  if (s.status === "rejected") return t("changeOrders.status.rejected");
  return t("changeOrders.detail.notSentYet");
}

export default function ChangeOrdersPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t, locale } = useLocale();

  const [summaries, setSummaries] = useState<ChangeOrderSummary[]>([]);
  const [selection, setSelection] = useState<"new" | string | null>(null);
  const [detail, setDetail] = useState<ChangeOrder | null>(null);
  const [boqItems, setBoqItems] = useState<FlatBoqItem[]>([]);
  const [listLoading, setListLoading] = useState(true);
  const [boqLoading, setBoqLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [boqError, setBoqError] = useState<string | null>(null);

  // Per-change-order "just sent in this session" results — GET /change-orders/{id} never
  // returns public_url/otp_code (hashed server-side), so this is the only place these values
  // ever live once returned from POST /change-orders/{id}/send. Same convention as
  // proposal/page.tsx's sendResults.
  const [sendResults, setSendResults] = useState<Record<string, ChangeOrderSendResult>>({});

  async function loadList(selectAfterId?: string) {
    setListLoading(true);
    setLoadError(null);
    try {
      const list = await getProjectChangeOrders(projectId);
      setSummaries(list);
      if (selectAfterId) {
        setSelection(selectAfterId);
      } else {
        setSelection((prev) => prev ?? (list[0] ? String(list[0].id) : "new"));
      }
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setListLoading(false);
    }
  }

  async function loadBoq() {
    setBoqLoading(true);
    setBoqError(null);
    try {
      const tree = await getProjectBoq(projectId);
      setBoqItems(flattenBoq(tree.categories));
    } catch (err) {
      setBoqError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setBoqLoading(false);
    }
  }

  // Intentional fetch-on-mount, matching every other screen in this app (see boq/page.tsx).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadList();
    loadBoq();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  useEffect(() => {
    if (!selection || selection === "new") {
      setDetail(null);
      return;
    }
    let cancelled = false;
    setDetailLoading(true);
    setLoadError(null);
    getChangeOrder(selection)
      .then((data) => {
        if (!cancelled) setDetail(data);
      })
      .catch((err) => {
        if (!cancelled) setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
      })
      .finally(() => {
        if (!cancelled) setDetailLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selection]);

  const boqById = useMemo(() => new Map(boqItems.map((b) => [b.id, b])), [boqItems]);

  function handleCreated(created: ChangeOrder) {
    setDetail(created);
    setSelection(String(created.id));
    setSummaries((prev) => [
      {
        id: created.id,
        number: created.number,
        status: created.status,
        price_delta: created.price_delta,
        timeline_delta_days: created.timeline_delta_days,
        sent_at: created.sent_at,
        approved_at: created.approved_at,
        applied_at: created.applied_at,
      },
      ...prev,
    ]);
  }

  function handleUpdated(updated: ChangeOrder) {
    setDetail(updated);
    setSummaries((prev) =>
      prev.map((s) =>
        String(s.id) === String(updated.id)
          ? {
              ...s,
              status: updated.status,
              price_delta: updated.price_delta,
              timeline_delta_days: updated.timeline_delta_days,
              sent_at: updated.sent_at,
              approved_at: updated.approved_at,
              applied_at: updated.applied_at,
            }
          : s,
      ),
    );
  }

  function handleSent(updated: ChangeOrder, result: ChangeOrderSendResult) {
    handleUpdated(updated);
    setSendResults((prev) => ({ ...prev, [String(updated.id)]: result }));
  }

  if (listLoading) return <LoadingScreen label={t("common.loading")} />;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("changeOrders.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("changeOrders.subtitle")}</p>
        </div>
        <Button onClick={() => setSelection("new")} disabled={selection === "new"}>
          {t("changeOrders.new.cta")}
        </Button>
      </div>

      {loadError ? (
        <ErrorBanner message={loadError} onRetry={() => loadList()} retryLabel={t("common.retry")} />
      ) : null}
      {boqError ? <ErrorBanner message={boqError} onRetry={loadBoq} retryLabel={t("common.retry")} /> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[280px_1fr]">
        <ChangeOrderList summaries={summaries} selection={selection} onSelect={setSelection} t={t} locale={locale} />

        <div>
          {selection === "new" ? (
            <ChangeOrderPanel
              key="new"
              projectId={projectId}
              detail={null}
              boqItems={boqItems}
              boqById={boqById}
              boqLoading={boqLoading}
              sendResult={null}
              onCreated={handleCreated}
              onUpdated={handleUpdated}
              onSent={handleSent}
              onApplied={handleUpdated}
              onCreateNew={() => setSelection("new")}
              t={t}
              locale={locale}
            />
          ) : detailLoading ? (
            <LoadingScreen label={t("common.loading")} />
          ) : detail ? (
            <ChangeOrderPanel
              key={String(detail.id)}
              projectId={projectId}
              detail={detail}
              boqItems={boqItems}
              boqById={boqById}
              boqLoading={boqLoading}
              sendResult={sendResults[String(detail.id)] ?? null}
              onCreated={handleCreated}
              onUpdated={handleUpdated}
              onSent={handleSent}
              onApplied={handleUpdated}
              onCreateNew={() => setSelection("new")}
              t={t}
              locale={locale}
            />
          ) : (
            <EmptyState message={t("common.notFound")} />
          )}
        </div>
      </div>
    </div>
  );
}

function ChangeOrderList({
  summaries,
  selection,
  onSelect,
  t,
  locale,
}: {
  summaries: ChangeOrderSummary[];
  selection: "new" | string | null;
  onSelect: (id: string) => void;
  t: T;
  locale: Locale;
}) {
  const { formatMoney } = useMoneyFormatter();

  return (
    <Card className="h-fit">
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("changeOrders.list.title")}</h2>
      </CardHeader>
      <CardBody className="flex flex-col gap-1 p-2">
        {summaries.length === 0 ? (
          <EmptyState message={`${t("changeOrders.empty.title")} ${t("changeOrders.empty.action")}`} />
        ) : (
          summaries.map((s) => {
            const active = selection === String(s.id);
            return (
              <button
                key={s.id}
                type="button"
                onClick={() => onSelect(String(s.id))}
                className={`flex flex-col gap-1 rounded-md px-3 py-2 text-left transition-colors ${
                  active
                    ? "bg-amber-600 text-white dark:bg-amber-500 dark:text-zinc-950"
                    : "text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                }`}
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-medium">{s.number}</span>
                  <Badge tone={STATUS_TONE[s.status]}>{t(`changeOrders.status.${s.status}`)}</Badge>
                </div>
                <span className={`text-xs ${active ? "text-white/80 dark:text-zinc-900/70" : "text-zinc-400"}`}>
                  {t("changeOrders.list.priceDelta")}: {formatMoney(s.price_delta)} ·{" "}
                  {formatTimelineDelta(s.timeline_delta_days, t)}
                </span>
                <span className={`text-xs ${active ? "text-white/70 dark:text-zinc-900/60" : "text-zinc-400"}`}>
                  {milestoneLine(s, t, locale)}
                </span>
              </button>
            );
          })
        )}
      </CardBody>
    </Card>
  );
}

interface ChangeOrderPanelProps {
  projectId: string;
  /** null means "creating a brand new change order" (no server row yet). */
  detail: ChangeOrder | null;
  boqItems: FlatBoqItem[];
  boqById: Map<string, FlatBoqItem>;
  boqLoading: boolean;
  sendResult: ChangeOrderSendResult | null;
  onCreated: (created: ChangeOrder) => void;
  onUpdated: (updated: ChangeOrder) => void;
  onSent: (updated: ChangeOrder, result: ChangeOrderSendResult) => void;
  onApplied: (updated: ChangeOrder) => void;
  onCreateNew: () => void;
  t: T;
  locale: Locale;
}

function ChangeOrderPanel({
  projectId,
  detail,
  boqItems,
  boqById,
  boqLoading,
  sendResult,
  onCreated,
  onUpdated,
  onSent,
  onApplied,
  onCreateNew,
  t,
  locale,
}: ChangeOrderPanelProps) {
  const { formatMoney } = useMoneyFormatter();
  const isCreate = detail === null;
  const isDraft = isCreate || detail.status === "draft";

  const savedReason = detail?.reason ?? "";
  const savedTimelineDeltaDays = detail?.timeline_delta_days != null ? String(detail.timeline_delta_days) : "";
  const savedRows = useMemo(() => (detail && detail.items.length > 0 ? detail.items.map(rowFromItem) : []), [detail]);

  const [reason, setReason] = useState(savedReason);
  const [timelineDeltaDays, setTimelineDeltaDays] = useState(savedTimelineDeltaDays);
  const [rows, setRows] = useState<ItemFormRow[]>(savedRows.length > 0 ? savedRows : [emptyRow()]);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [applying, setApplying] = useState(false);
  const [applyError, setApplyError] = useState<string | null>(null);
  const [applyNoContract, setApplyNoContract] = useState(false);

  const savedSnapshot = useMemo(
    () => snapshotKey(savedReason, savedTimelineDeltaDays, savedRows.length > 0 ? savedRows : [emptyRow()]),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [detail],
  );
  const currentSnapshot = snapshotKey(reason, timelineDeltaDays, rows);
  const dirty = isCreate ? false : currentSnapshot !== savedSnapshot;

  const reasonValid = reason.trim().length > 0;
  const rowsValid = rows.length > 0 && rows.every(rowIsValid);
  const canSave = reasonValid && rowsValid;
  const totalPreview = rows.reduce((sum, r) => sum + previewLineDelta(r, boqById), 0);

  function updateRow(key: string, patch: Partial<ItemFormRow>) {
    setRows((prev) => prev.map((r) => (r.key === key ? { ...r, ...patch } : r)));
  }

  function removeRow(key: string) {
    setRows((prev) => (prev.length > 1 ? prev.filter((r) => r.key !== key) : prev));
  }

  function addRow() {
    setRows((prev) => [...prev, emptyRow()]);
  }

  async function handleSave() {
    setSaving(true);
    setSaveError(null);
    try {
      const payload = {
        reason: reason.trim(),
        timeline_delta_days: timelineDeltaDays.trim() === "" ? null : Number(timelineDeltaDays),
        items: rows.map(rowToInput),
      };
      if (isCreate) {
        const created = await createChangeOrder(projectId, payload);
        onCreated(created);
      } else {
        const updated = await updateChangeOrder(detail.id, payload);
        onUpdated(updated);
      }
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : t("changeOrders.editor.save.failed"));
    } finally {
      setSaving(false);
    }
  }

  async function handleSend() {
    if (isCreate) return;
    setSending(true);
    setSendError(null);
    try {
      const result = await sendChangeOrder(detail.id);
      const updated = await getChangeOrder(detail.id);
      onSent(updated, result);
    } catch (err) {
      setSendError(err instanceof ApiError ? err.message : t("changeOrders.send.failed"));
    } finally {
      setSending(false);
    }
  }

  async function handleApply() {
    if (isCreate) return;
    setApplying(true);
    setApplyError(null);
    setApplyNoContract(false);
    try {
      const updated = await applyChangeOrder(detail.id);
      onApplied(updated);
    } catch (err) {
      if (err instanceof ApiError && err.code === "CHANGE_ORDER_NO_CONTRACT") {
        setApplyError(t("changeOrders.apply.noContract"));
        setApplyNoContract(true);
      } else {
        setApplyError(err instanceof ApiError ? err.message : t("changeOrders.apply.failed"));
      }
    } finally {
      setApplying(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <h2 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
              {isCreate ? t("changeOrders.editor.createTitle") : detail.number}
            </h2>
            {!isCreate ? <Badge tone={STATUS_TONE[detail.status]}>{t(`changeOrders.status.${detail.status}`)}</Badge> : null}
          </div>
          {!isDraft ? (
            <Button onClick={onCreateNew}>{t("changeOrders.createNew.cta")}</Button>
          ) : null}
        </CardHeader>
        {!isCreate ? (
          <CardBody className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <Field label={t("changeOrders.detail.requestedBy")} value={detail.requested_by?.name ?? t("common.na")} />
            <Field
              label={t("changeOrders.detail.sentAt")}
              value={detail.sent_at ? formatDateTime(detail.sent_at, locale) : t("changeOrders.detail.notSentYet")}
            />
            <Field
              label={t("changeOrders.detail.approvedAt")}
              value={detail.approved_at ? formatDateTime(detail.approved_at, locale) : t("changeOrders.detail.notApprovedYet")}
            />
            <Field
              label={t("changeOrders.detail.appliedAt")}
              value={detail.applied_at ? formatDateTime(detail.applied_at, locale) : t("changeOrders.detail.notAppliedYet")}
            />
            <Field label={t("changeOrders.detail.priceDelta")} value={formatMoney(detail.price_delta)} />
            <Field label={t("changeOrders.detail.timelineDelta")} value={formatTimelineDelta(detail.timeline_delta_days, t)} />
          </CardBody>
        ) : null}
      </Card>

      {!isCreate && detail.status === "rejected" ? (
        <ErrorBanner message={t("changeOrders.detail.rejectedNote")} />
      ) : null}

      {!isCreate && detail.status === "sent" ? <SentShareCard result={sendResult} t={t} /> : null}

      {!isCreate && detail.status === "approved" ? (
        <Card>
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("changeOrders.apply.cta")}</h2>
          </CardHeader>
          <CardBody className="flex flex-col gap-3">
            {applyError ? (
              <div className="flex flex-col gap-2">
                <ErrorBanner message={applyError} />
                {applyNoContract ? (
                  <Link
                    href={`/projects/${projectId}/contract`}
                    className="text-sm font-medium text-zinc-900 underline underline-offset-2 dark:text-zinc-50"
                  >
                    {t("changeOrders.apply.goToContract")}
                  </Link>
                ) : null}
              </div>
            ) : null}
            <div>
              <Button onClick={handleApply} disabled={applying}>
                {applying ? t("changeOrders.apply.applying") : t("changeOrders.apply.cta")}
              </Button>
            </div>
          </CardBody>
        </Card>
      ) : null}

      {!isCreate && detail.status === "applied" ? (
        <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
          {t("changeOrders.apply.appliedNote")}
        </div>
      ) : null}

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
            {isDraft ? (isCreate ? t("changeOrders.editor.createTitle") : t("changeOrders.editor.title")) : t("changeOrders.editor.readonlyTitle")}
          </h2>
          {!isDraft ? <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">{t("changeOrders.editor.readonlyNote")}</p> : null}
        </CardHeader>
        <CardBody className="flex flex-col gap-4">
          {isDraft ? (
            <>
              <FormField label={t("changeOrders.editor.fields.reason")} htmlFor="co-reason">
                <textarea
                  id="co-reason"
                  rows={3}
                  value={reason}
                  placeholder={t("changeOrders.editor.fields.reasonPlaceholder")}
                  onChange={(e) => setReason(e.target.value)}
                  className={TEXTAREA_CLASSES}
                />
              </FormField>
              <FormField label={t("changeOrders.editor.fields.timelineDeltaDays")} htmlFor="co-timeline">
                <input
                  id="co-timeline"
                  type="number"
                  step="1"
                  value={timelineDeltaDays}
                  onChange={(e) => setTimelineDeltaDays(e.target.value)}
                  className={`${INPUT_CLASSES} max-w-xs`}
                />
                <p className="mt-1 text-xs text-zinc-400">{t("changeOrders.editor.fields.timelineDeltaHint")}</p>
              </FormField>

              <div className="flex flex-col gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("changeOrders.editor.items.title")}</h3>
                  <Button type="button" variant="secondary" onClick={addRow}>
                    {t("changeOrders.editor.items.addRow")}
                  </Button>
                </div>
                {rows.map((row, index) => (
                  <ItemRow
                    key={row.key}
                    row={row}
                    index={index}
                    onChange={(patch) => updateRow(row.key, patch)}
                    onRemove={() => removeRow(row.key)}
                    canRemove={rows.length > 1}
                    boqItems={boqItems}
                    boqById={boqById}
                    boqLoading={boqLoading}
                    t={t}
                    locale={locale}
                  />
                ))}
              </div>

              <div className="flex items-center justify-between border-t border-zinc-200 pt-4 dark:border-zinc-800">
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{t("changeOrders.editor.total.title")}</p>
                  <p className="mt-0.5 text-xs text-zinc-400">{t("changeOrders.editor.total.note")}</p>
                </div>
                <span className={`text-xl font-semibold ${totalPreview < 0 ? "text-red-600 dark:text-red-400" : "text-zinc-900 dark:text-zinc-100"}`}>
                  {formatMoney(totalPreview)}
                </span>
              </div>

              <div className="flex flex-col gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                {saveError ? <ErrorBanner message={saveError} /> : null}
                {sendError ? <ErrorBanner message={sendError} /> : null}
                {!reasonValid || !rowsValid ? (
                  <p className="text-xs text-amber-600 dark:text-amber-400">
                    {!reasonValid ? t("changeOrders.editor.validation.reasonRequired") : t("changeOrders.editor.validation.itemsRequired")}
                  </p>
                ) : null}
                <div className="flex flex-wrap items-center gap-3">
                  <Button variant="secondary" onClick={handleSave} disabled={!canSave || saving || (!isCreate && !dirty)}>
                    {saving ? t("changeOrders.editor.save.saving") : isCreate ? t("changeOrders.new.saveCta") : t("changeOrders.editor.save.cta")}
                  </Button>
                  {!isCreate ? (
                    <Button onClick={handleSend} disabled={dirty || sending}>
                      {sending ? t("changeOrders.send.sending") : t("changeOrders.send.cta")}
                    </Button>
                  ) : null}
                  {dirty ? <span className="text-xs text-amber-600 dark:text-amber-400">{t("changeOrders.editor.unsavedHint")}</span> : null}
                </div>
              </div>
            </>
          ) : (
            <ReadonlyChangeOrderContent detail={detail as ChangeOrder} t={t} locale={locale} />
          )}
        </CardBody>
      </Card>
    </div>
  );
}

function ItemRow({
  row,
  index,
  onChange,
  onRemove,
  canRemove,
  boqItems,
  boqById,
  boqLoading,
  t,
  locale,
}: {
  row: ItemFormRow;
  index: number;
  onChange: (patch: Partial<ItemFormRow>) => void;
  onRemove: () => void;
  canRemove: boolean;
  boqItems: FlatBoqItem[];
  boqById: Map<string, FlatBoqItem>;
  boqLoading: boolean;
  t: T;
  locale: Locale;
}) {
  const { formatMoney } = useMoneyFormatter();
  const showBoqPicker = row.action !== "add";
  const showNewPrice = row.action !== "remove";
  const delta = previewLineDelta(row, boqById);

  function handleActionChange(action: ChangeOrderItemAction) {
    if (action === "add") {
      onChange({ action, boq_item_id: "", old_unit_price: "" });
    } else if (action === "remove") {
      onChange({ action, new_unit_price: "" });
    } else {
      onChange({ action });
    }
  }

  function handleBoqPick(id: string) {
    const boqItem = boqById.get(id);
    if (boqItem) {
      onChange({ boq_item_id: id, description: boqItem.name, unit: boqItem.unit, quantity: boqItem.quantity });
    } else {
      onChange({ boq_item_id: id });
    }
  }

  return (
    <div className="flex flex-col gap-3 rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="text-xs font-medium uppercase tracking-wide text-zinc-400">
            {t("changeOrders.editor.items.rowLabel")} {index + 1}
          </span>
          <select
            value={row.action}
            onChange={(e) => handleActionChange(e.target.value as ChangeOrderItemAction)}
            className={`${INPUT_CLASSES} w-auto`}
          >
            <option value="add">{t("changeOrders.editor.items.action.add")}</option>
            <option value="remove">{t("changeOrders.editor.items.action.remove")}</option>
            <option value="modify">{t("changeOrders.editor.items.action.modify")}</option>
          </select>
        </div>
        {canRemove ? (
          <button
            type="button"
            onClick={onRemove}
            className="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
          >
            {t("changeOrders.editor.items.removeRow")}
          </button>
        ) : null}
      </div>

      {showBoqPicker ? (
        <FormField label={t("changeOrders.editor.items.fields.boqItem")} htmlFor={`${row.key}-boq`}>
          <select
            id={`${row.key}-boq`}
            value={row.boq_item_id}
            onChange={(e) => handleBoqPick(e.target.value)}
            className={INPUT_CLASSES}
            disabled={boqLoading}
          >
            <option value="" disabled>
              {boqLoading
                ? t("common.loading")
                : boqItems.length === 0
                  ? t("changeOrders.editor.items.noBoqItems")
                  : t("changeOrders.editor.items.fields.boqItemPlaceholder")}
            </option>
            {boqItems.map((b) => (
              <option key={b.id} value={b.id}>
                {b.categoryPath} — {b.name} ({formatMoney(b.clientUnitPrice)})
              </option>
            ))}
          </select>
        </FormField>
      ) : null}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label={t("changeOrders.editor.items.fields.description")} htmlFor={`${row.key}-desc`}>
          <input
            id={`${row.key}-desc`}
            value={row.description}
            onChange={(e) => onChange({ description: e.target.value })}
            className={INPUT_CLASSES}
          />
        </FormField>
        <FormField label={t("changeOrders.editor.items.fields.unit")} htmlFor={`${row.key}-unit`}>
          <input id={`${row.key}-unit`} value={row.unit} onChange={(e) => onChange({ unit: e.target.value })} className={INPUT_CLASSES} />
        </FormField>
        <FormField label={t("changeOrders.editor.items.fields.quantity")} htmlFor={`${row.key}-qty`}>
          <input
            id={`${row.key}-qty`}
            type="number"
            step="0.01"
            min="0.01"
            value={row.quantity}
            onChange={(e) => onChange({ quantity: e.target.value })}
            className={INPUT_CLASSES}
          />
        </FormField>
        {row.action !== "add" ? (
          <FormField label={t("changeOrders.editor.items.fields.oldUnitPrice")} htmlFor={`${row.key}-old`}>
            <input
              id={`${row.key}-old`}
              type="number"
              step="0.01"
              min="0"
              placeholder={t("changeOrders.editor.items.fields.oldUnitPriceHint")}
              value={row.old_unit_price}
              onChange={(e) => onChange({ old_unit_price: e.target.value })}
              className={INPUT_CLASSES}
            />
          </FormField>
        ) : null}
        {showNewPrice ? (
          <FormField label={t("changeOrders.editor.items.fields.newUnitPrice")} htmlFor={`${row.key}-new`}>
            <input
              id={`${row.key}-new`}
              type="number"
              step="0.01"
              min="0"
              value={row.new_unit_price}
              onChange={(e) => onChange({ new_unit_price: e.target.value })}
              className={INPUT_CLASSES}
            />
          </FormField>
        ) : null}
      </div>

      <div className="flex items-center justify-between border-t border-zinc-100 pt-2 text-xs dark:border-zinc-800/60">
        <span className="text-zinc-400">{t("changeOrders.editor.items.fields.lineDeltaPreview")}</span>
        <span className={`font-medium ${delta < 0 ? "text-red-600 dark:text-red-400" : "text-zinc-900 dark:text-zinc-100"}`}>
          {formatMoney(delta)}
        </span>
      </div>
    </div>
  );
}

function ReadonlyChangeOrderContent({ detail, t, locale }: { detail: ChangeOrder; t: T; locale: Locale }) {
  const { formatMoney } = useMoneyFormatter();

  return (
    <div className="flex flex-col gap-4">
      <div>
        <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{t("changeOrders.detail.reason")}</p>
        <p className="mt-1 whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-200">{detail.reason}</p>
      </div>

      {detail.items.length === 0 ? (
        <EmptyState message={t("common.notFound")} />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] border-collapse text-sm">
            <thead>
              <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                <th className="px-3 py-2">{t("changeOrders.preview.items.columns.action")}</th>
                <th className="px-3 py-2">{t("changeOrders.preview.items.columns.description")}</th>
                <th className="px-3 py-2 text-right">{t("changeOrders.preview.items.columns.quantity")}</th>
                <th className="px-3 py-2">{t("changeOrders.preview.items.columns.unit")}</th>
                <th className="px-3 py-2 text-right">{t("changeOrders.preview.items.columns.oldPrice")}</th>
                <th className="px-3 py-2 text-right">{t("changeOrders.preview.items.columns.newPrice")}</th>
                <th className="px-3 py-2 text-right">{t("changeOrders.preview.items.columns.lineDelta")}</th>
              </tr>
            </thead>
            <tbody>
              {detail.items.map((item) => (
                <tr key={item.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                  <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">
                    {t(`changeOrders.editor.items.action.${item.action}`)}
                  </td>
                  <td className="px-3 py-2 align-top text-zinc-900 dark:text-zinc-100">{item.description}</td>
                  <td className="px-3 py-2 text-right align-top text-zinc-600 dark:text-zinc-300">{item.quantity}</td>
                  <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">{item.unit}</td>
                  <td className="px-3 py-2 text-right align-top text-zinc-600 dark:text-zinc-300">
                    {item.old_unit_price !== null ? formatMoney(item.old_unit_price) : t("common.na")}
                  </td>
                  <td className="px-3 py-2 text-right align-top text-zinc-600 dark:text-zinc-300">
                    {item.new_unit_price !== null ? formatMoney(item.new_unit_price) : t("common.na")}
                  </td>
                  <td className="px-3 py-2 text-right align-top font-medium text-zinc-900 dark:text-zinc-100">
                    {formatMoney(item.line_delta)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="flex items-center justify-between border-t border-zinc-200 pt-3 dark:border-zinc-800">
        <span className="text-base font-semibold text-zinc-900 dark:text-zinc-50">{t("changeOrders.detail.priceDelta")}</span>
        <span className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">{formatMoney(detail.price_delta)}</span>
      </div>
    </div>
  );
}

function SentShareCard({ result, t }: { result: ChangeOrderSendResult | null; t: T }) {
  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
          {result ? t("changeOrders.send.resultTitle") : t("changeOrders.reshare.title")}
        </h2>
      </CardHeader>
      <CardBody className="flex flex-col gap-3">
        {result ? (
          <>
            <p className="text-sm text-zinc-600 dark:text-zinc-300">{t("changeOrders.send.resultNote")}</p>
            <CopyField label={t("changeOrders.send.linkLabel")} value={result.public_url} t={t} />
            <CopyField label={t("changeOrders.send.otpLabel")} value={result.otp_code} t={t} />
            <p className="text-xs text-zinc-400">{t("changeOrders.send.otpOnceNote")}</p>
          </>
        ) : (
          <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("changeOrders.reshare.onceOnlyNote")}</p>
        )}
      </CardBody>
    </Card>
  );
}

function CopyField({ label, value, t }: { label: string; value: string; t: T }) {
  const [copied, setCopied] = useState(false);

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard API unavailable (older browser / non-secure context) — the value is still
      // selectable/visible in the input below, so this is a soft failure, not a blocker.
    }
  }

  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{label}</p>
      <div className="mt-1 flex items-center gap-2">
        <input
          readOnly
          value={value}
          onFocus={(e) => e.currentTarget.select()}
          className="w-full min-w-0 rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
        />
        <Button type="button" variant="secondary" className="shrink-0 py-2" onClick={handleCopy}>
          {copied ? t("changeOrders.send.copied") : t("changeOrders.send.copy")}
        </Button>
      </div>
    </div>
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
