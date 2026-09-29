"use client";

/**
 * S07 — BOQ Builder (PRD §4.1, PROJECT_CONTEXT.md Sprint 2 UX).
 *
 * Layout: left panel (category tree + room filter), center (spreadsheet-like line-item grid),
 * right panel (category/grand totals). Sprint 2 shows both direct_cost (internal) and
 * client_total side by side — there's no client-facing/internal view toggle yet, that's Sprint
 * 3's Pricing Panel (S08).
 *
 * State design: `items` is a flat array of every non-archived BoqItem for the project, loaded
 * once from GET .../boq (which nests items under categories) via `flattenItemsFromTree`, then
 * kept in sync locally on every create/update/archive/bulk-move — no full-tree refetch after a
 * single-item edit. Category/room subtotals and the grand total are *computed from `items`
 * client-side* (via computeSubtotal) rather than read from the server tree's `subtotal` fields,
 * so they can't go stale between edits and stay reactive on every keystroke — this is what makes
 * "quantity × unit price recalculates live" (PROJECT_CONTEXT.md) true for the summary panel too,
 * not just the row it's on. The category *tree structure* (id/name/parent_id/children) still
 * comes from the server and is only refetched wholesale after bulk operations that can touch many
 * rows at once (apply-template, CSV import).
 *
 * Rooms are managed the same way as categories: POST /projects/{id}/rooms creates a room (see
 * handleAddRoom), which is appended to local `rooms` state directly (no full-tree refetch) so it
 * shows up in the room filter list immediately.
 */

import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent, type KeyboardEvent, type ReactNode } from "react";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import {
  applyBoqTemplate,
  archiveBoqItem,
  createBoqCategory,
  createBoqItem,
  createBoqRoom,
  exportBoq,
  getBoqTemplates,
  getProjectBoq,
  importBoq,
  updateBoqItem,
} from "@/lib/api/resources/boq";
import { getProject } from "@/lib/api/resources/projects";
import { PricingPanel } from "@/components/pricing/PricingPanel";
import type {
  BoqCategoryNode,
  BoqImportResult,
  BoqItem,
  BoqItemFormInput,
  BoqRoom,
  BoqTemplateCategoryNode,
  Project,
} from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { useMoneyFormatter } from "@/lib/format/useMoneyFormatter";

const INPUT_CLASSES =
  "rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";

const CELL_INPUT_CLASSES =
  "w-full min-w-0 rounded border border-transparent bg-transparent px-1.5 py-1 text-sm focus:border-zinc-400 focus:bg-white focus:outline-none dark:focus:border-zinc-600 dark:focus:bg-zinc-900";

interface FlatCategory {
  id: string;
  name: string;
  parent_id: string | null;
  depth: number;
}

interface FlatTemplateCategory {
  id: string;
  name: string;
  depth: number;
}

function flattenCategories(nodes: BoqCategoryNode[], depth = 0): FlatCategory[] {
  return nodes.flatMap((node) => [
    { id: String(node.id), name: node.name, parent_id: node.parent_id === null ? null : String(node.parent_id), depth },
    ...flattenCategories(node.children, depth + 1),
  ]);
}

function flattenItemsFromTree(nodes: BoqCategoryNode[]): BoqItem[] {
  return nodes.flatMap((node) => [...node.items, ...flattenItemsFromTree(node.children)]);
}

function flattenTemplateCategories(nodes: BoqTemplateCategoryNode[], depth = 0): FlatTemplateCategory[] {
  return nodes.flatMap((node) => [
    { id: String(node.id), name: node.name, depth },
    ...flattenTemplateCategories(node.children, depth + 1),
  ]);
}

function toNum(value: number | string | null | undefined): number {
  if (value === null || value === undefined || value === "") return 0;
  const n = typeof value === "string" ? Number(value) : value;
  return Number.isFinite(n) ? n : 0;
}

/** Client-side quantity × cost recalculation — see file docblock. */
function computeItemTotals(item: BoqItem): { direct_cost: number; client_total: number } {
  const qty = toNum(item.quantity);
  const direct = (toNum(item.material_unit_cost) + toNum(item.labor_unit_cost) + toNum(item.other_unit_cost)) * qty;
  const client = toNum(item.client_unit_price) * qty;
  return { direct_cost: direct, client_total: client };
}

/**
 * Merges only the given fields (plus `updated_at`) from a server response into the matching
 * local item, rather than replacing the whole item wholesale.
 *
 * Why this matters: each grid cell autosaves independently on blur (one PATCH per field, see
 * commitItemField), so two cells in the same row can have requests in flight at once (e.g. the
 * user edits material cost then tabs straight into client price before the first save returns).
 * Each PATCH response is a *full* fresh() snapshot of the item as it existed in the DB at that
 * request's completion time. If responses arrive out of order (always possible over the network,
 * regardless of request order) and the whole item were replaced on the last-arriving response,
 * an earlier snapshot that predates the *other* field's write would silently revert it — verified
 * live during S07 testing: committing material_unit_cost then client_unit_price back-to-back
 * occasionally reset client_unit_price to its old value. Merging only the field(s) this specific
 * request was responsible for avoids that clobber entirely.
 */
function mergeItemFields<K extends keyof BoqItem>(prev: BoqItem[], updated: BoqItem, fields: K[]): BoqItem[] {
  return prev.map((it) => {
    if (String(it.id) !== String(updated.id)) return it;
    const patch = {} as Pick<BoqItem, K>;
    fields.forEach((f) => {
      patch[f] = updated[f];
    });
    return { ...it, ...patch, updated_at: updated.updated_at };
  });
}

function computeSubtotal(items: BoqItem[]): { direct_cost: number; client_total: number } {
  return items.reduce(
    (acc, item) => {
      const t = computeItemTotals(item);
      return { direct_cost: acc.direct_cost + t.direct_cost, client_total: acc.client_total + t.client_total };
    },
    { direct_cost: 0, client_total: 0 },
  );
}

export default function BoqBuilderPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t, locale } = useLocale();
  const { formatMoney } = useMoneyFormatter();

  const [project, setProject] = useState<Project | null>(null);
  const [categoryTree, setCategoryTree] = useState<BoqCategoryNode[]>([]);
  const [rooms, setRooms] = useState<BoqRoom[]>([]);
  const [items, setItems] = useState<BoqItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const [selectedCategoryId, setSelectedCategoryId] = useState<string | null>(null);
  const [selectedRoomId, setSelectedRoomId] = useState<string | null>(null);
  const [selectedItemIds, setSelectedItemIds] = useState<Set<string>>(new Set());

  const categories = useMemo(() => flattenCategories(categoryTree), [categoryTree]);
  const categoryNameById = useMemo(() => new Map(categories.map((c) => [c.id, c.name])), [categories]);

  async function loadBoq() {
    setLoading(true);
    setError(null);
    try {
      const [boq, proj] = await Promise.all([getProjectBoq(projectId), getProject(projectId)]);
      setCategoryTree(boq.categories);
      setRooms(boq.rooms);
      setItems(flattenItemsFromTree(boq.categories));
      setProject(proj);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  // Intentional fetch-on-mount, see dashboard/page.tsx for rationale.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadBoq();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  const filteredItems = useMemo(() => {
    return items.filter((item) => {
      if (selectedCategoryId !== null && String(item.category_id) !== selectedCategoryId) return false;
      if (selectedRoomId !== null && String(item.room_id ?? "") !== selectedRoomId) return false;
      return true;
    });
  }, [items, selectedCategoryId, selectedRoomId]);

  const grandTotal = useMemo(() => computeSubtotal(items), [items]);
  const roomSubtotal = useMemo(
    () => (selectedRoomId !== null ? computeSubtotal(items.filter((i) => String(i.room_id ?? "") === selectedRoomId)) : null),
    [items, selectedRoomId],
  );

  // ---------------------------------------------------------------------
  // Inline cell editing (spreadsheet-like grid)
  // ---------------------------------------------------------------------

  type EditableTextField = "name" | "unit" | "quantity" | "material_unit_cost" | "labor_unit_cost" | "other_unit_cost" | "client_unit_price";

  function setLocalItemField(itemId: string | number, field: EditableTextField, value: string) {
    setItems((prev) => prev.map((it) => (String(it.id) === String(itemId) ? { ...it, [field]: value } : it)));
  }

  async function commitItemField(item: BoqItem, field: keyof BoqItemFormInput) {
    setActionError(null);
    try {
      const updated = await updateBoqItem(item.id, { [field]: item[field] } as Partial<BoqItemFormInput>);
      // Merge only this field (+ its computed totals) — see mergeItemFields' docblock for why a
      // full-item replacement here would risk clobbering a concurrently in-flight edit to a
      // different field on the same row.
      setItems((prev) => mergeItemFields(prev, updated, [field, "direct_cost", "client_total"]));
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  function handleCellKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key !== "Enter") return;
    e.preventDefault();
    const { row, col } = e.currentTarget.dataset;
    e.currentTarget.blur();
    if (row === undefined || col === undefined) return;
    const nextRow = Number(row) + 1;
    const next = document.querySelector<HTMLInputElement>(`[data-row="${nextRow}"][data-col="${col}"]`);
    next?.focus();
    next?.select();
  }

  async function handleRoomChange(item: BoqItem, roomId: string) {
    setActionError(null);
    const previous = item.room_id;
    setItems((prev) =>
      prev.map((it) => (String(it.id) === String(item.id) ? { ...it, room_id: roomId || null } : it)),
    );
    try {
      const updated = await updateBoqItem(item.id, { room_id: roomId || null });
      setItems((prev) => mergeItemFields(prev, updated, ["room_id"]));
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("common.unknownError"));
      setItems((prev) => prev.map((it) => (String(it.id) === String(item.id) ? { ...it, room_id: previous } : it)));
    }
  }

  async function handleDuplicate(item: BoqItem) {
    setActionError(null);
    try {
      const created = await createBoqItem(projectId, {
        category_id: item.category_id,
        room_id: item.room_id ?? undefined,
        name: `${item.name} (copy)`,
        description: item.description ?? undefined,
        quantity: item.quantity,
        unit: item.unit,
        material_unit_cost: item.material_unit_cost,
        labor_unit_cost: item.labor_unit_cost,
        other_unit_cost: item.other_unit_cost,
        client_unit_price: item.client_unit_price,
        notes: item.notes ?? undefined,
      });
      setItems((prev) => [...prev, created]);
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  async function handleArchive(item: BoqItem) {
    if (!window.confirm(t("boq.items.archiveConfirm"))) return;
    setActionError(null);
    try {
      await archiveBoqItem(item.id);
      setItems((prev) => prev.filter((it) => String(it.id) !== String(item.id)));
      setSelectedItemIds((prev) => {
        const next = new Set(prev);
        next.delete(String(item.id));
        return next;
      });
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  function toggleSelected(itemId: string | number) {
    setSelectedItemIds((prev) => {
      const next = new Set(prev);
      const key = String(itemId);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  function toggleSelectAllVisible() {
    setSelectedItemIds((prev) => {
      const visibleIds = filteredItems.map((i) => String(i.id));
      const allSelected = visibleIds.length > 0 && visibleIds.every((id) => prev.has(id));
      if (allSelected) return new Set();
      return new Set(visibleIds);
    });
  }

  // ---------------------------------------------------------------------
  // Bulk category reassignment
  // ---------------------------------------------------------------------

  const [bulkTargetCategoryId, setBulkTargetCategoryId] = useState("");
  const [bulkMoving, setBulkMoving] = useState(false);

  async function handleBulkMove() {
    if (!bulkTargetCategoryId || selectedItemIds.size === 0) return;
    setBulkMoving(true);
    setActionError(null);
    try {
      const updates = await Promise.all(
        Array.from(selectedItemIds).map((id) => updateBoqItem(id, { category_id: bulkTargetCategoryId })),
      );
      setItems((prev) =>
        updates.reduce((acc, updated) => mergeItemFields(acc, updated, ["category_id"]), prev),
      );
      setSelectedItemIds(new Set());
      setBulkTargetCategoryId("");
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setBulkMoving(false);
    }
  }

  // ---------------------------------------------------------------------
  // Add category
  // ---------------------------------------------------------------------

  const [showAddCategory, setShowAddCategory] = useState(false);
  const [newCategoryName, setNewCategoryName] = useState("");
  const [newCategoryParentId, setNewCategoryParentId] = useState("");
  const [addingCategory, setAddingCategory] = useState(false);
  const [addCategoryError, setAddCategoryError] = useState<string | null>(null);

  async function handleAddCategory(e: FormEvent) {
    e.preventDefault();
    setAddCategoryError(null);
    setAddingCategory(true);
    try {
      await createBoqCategory(projectId, {
        name: newCategoryName,
        parent_id: newCategoryParentId || undefined,
      });
      setNewCategoryName("");
      setNewCategoryParentId("");
      setShowAddCategory(false);
      await loadBoq();
    } catch (err) {
      setAddCategoryError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setAddingCategory(false);
    }
  }

  // ---------------------------------------------------------------------
  // Add room
  // ---------------------------------------------------------------------

  const [showAddRoom, setShowAddRoom] = useState(false);
  const [newRoomName, setNewRoomName] = useState("");
  const [newRoomArea, setNewRoomArea] = useState("");
  const [addingRoom, setAddingRoom] = useState(false);
  const [addRoomError, setAddRoomError] = useState<string | null>(null);

  async function handleAddRoom(e: FormEvent) {
    e.preventDefault();
    setAddRoomError(null);
    setAddingRoom(true);
    try {
      const created = await createBoqRoom(projectId, {
        name: newRoomName,
        area_m2: newRoomArea || undefined,
      });
      // Appended directly to local state (no full-tree refetch) so the new room shows up in
      // the filter list immediately — same approach as handleDuplicate/handleAddItem for items.
      setRooms((prev) => [...prev, created]);
      setNewRoomName("");
      setNewRoomArea("");
      setShowAddRoom(false);
    } catch (err) {
      setAddRoomError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setAddingRoom(false);
    }
  }

  // ---------------------------------------------------------------------
  // Add item
  // ---------------------------------------------------------------------

  const [showAddItem, setShowAddItem] = useState(false);
  const [newItemCategoryId, setNewItemCategoryId] = useState("");
  const [newItemRoomId, setNewItemRoomId] = useState("");
  const [newItemName, setNewItemName] = useState("");
  const [newItemUnit, setNewItemUnit] = useState("");
  const [newItemQuantity, setNewItemQuantity] = useState("1");
  const [addingItem, setAddingItem] = useState(false);
  const [addItemError, setAddItemError] = useState<string | null>(null);

  useEffect(() => {
    if (showAddItem) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setNewItemCategoryId(selectedCategoryId ?? categories[0]?.id ?? "");
      setNewItemRoomId(selectedRoomId ?? "");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showAddItem]);

  async function handleAddItem(e: FormEvent) {
    e.preventDefault();
    setAddItemError(null);
    if (!newItemCategoryId) {
      setAddItemError(t("boq.add.categoryPlaceholder"));
      return;
    }
    setAddingItem(true);
    try {
      const created = await createBoqItem(projectId, {
        category_id: newItemCategoryId,
        room_id: newItemRoomId || undefined,
        name: newItemName,
        unit: newItemUnit,
        quantity: newItemQuantity,
      });
      setItems((prev) => [...prev, created]);
      setNewItemName("");
      setNewItemUnit("");
      setNewItemQuantity("1");
    } catch (err) {
      setAddItemError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setAddingItem(false);
    }
  }

  // ---------------------------------------------------------------------
  // Templates
  // ---------------------------------------------------------------------

  const [showTemplates, setShowTemplates] = useState(false);
  const [templateCategories, setTemplateCategories] = useState<FlatTemplateCategory[] | null>(null);
  const [templatesLoading, setTemplatesLoading] = useState(false);
  const [selectedTemplateId, setSelectedTemplateId] = useState("");
  const [applyingTemplate, setApplyingTemplate] = useState(false);
  const [templateError, setTemplateError] = useState<string | null>(null);

  useEffect(() => {
    if (showTemplates && templateCategories === null && !templatesLoading) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setTemplatesLoading(true);
      getBoqTemplates()
        .then((tree) => setTemplateCategories(flattenTemplateCategories(tree.categories)))
        .catch((err) => setTemplateError(err instanceof ApiError ? err.message : t("common.unknownError")))
        .finally(() => setTemplatesLoading(false));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showTemplates]);

  async function handleApplyTemplate() {
    if (!selectedTemplateId) return;
    setApplyingTemplate(true);
    setTemplateError(null);
    try {
      const result = await applyBoqTemplate(projectId, selectedTemplateId);
      setCategoryTree(result.boq.categories);
      setRooms(result.boq.rooms);
      setItems(flattenItemsFromTree(result.boq.categories));
      setShowTemplates(false);
      setSelectedTemplateId("");
    } catch (err) {
      setTemplateError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setApplyingTemplate(false);
    }
  }

  // ---------------------------------------------------------------------
  // Import / export
  // ---------------------------------------------------------------------

  const [showImport, setShowImport] = useState(false);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importing, setImporting] = useState(false);
  const [importError, setImportError] = useState<string | null>(null);
  const [importResult, setImportResult] = useState<BoqImportResult | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  async function handleImport(e: FormEvent) {
    e.preventDefault();
    if (!importFile) return;
    setImporting(true);
    setImportError(null);
    setImportResult(null);
    try {
      const result = await importBoq(projectId, importFile);
      setImportResult(result);
      setImportFile(null);
      if (fileInputRef.current) fileInputRef.current.value = "";
      await loadBoq();
    } catch (err) {
      setImportError(err instanceof Error ? err.message : t("common.unknownError"));
    } finally {
      setImporting(false);
    }
  }

  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  async function handleExport() {
    setExporting(true);
    setExportError(null);
    try {
      await exportBoq(projectId, project?.code);
    } catch {
      setExportError(t("boq.export.failed"));
    } finally {
      setExporting(false);
    }
  }

  if (loading) return <LoadingScreen label={t("common.loading")} />;
  if (error) return <ErrorBanner message={error} onRetry={loadBoq} retryLabel={t("common.retry")} />;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("boq.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("boq.subtitle")}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="secondary" onClick={() => setShowTemplates((v) => !v)}>
            {t("boq.template.cta")}
          </Button>
          <Button variant="secondary" onClick={() => setShowImport((v) => !v)}>
            {t("boq.import.cta")}
          </Button>
          <Button variant="secondary" onClick={handleExport} disabled={exporting}>
            {t("boq.export.cta")}
          </Button>
        </div>
      </div>

      {actionError ? <ErrorBanner message={actionError} /> : null}
      {exportError ? <ErrorBanner message={exportError} /> : null}

      {showTemplates ? (
        <Card>
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boq.template.title")}</h2>
          </CardHeader>
          <CardBody className="flex flex-col gap-3">
            {templatesLoading ? (
              <LoadingScreen label={t("common.loading")} />
            ) : templateCategories && templateCategories.length === 0 ? (
              <EmptyState message={t("boq.template.empty")} />
            ) : (
              <div className="flex flex-wrap items-center gap-3">
                <select
                  value={selectedTemplateId}
                  onChange={(e) => setSelectedTemplateId(e.target.value)}
                  className={INPUT_CLASSES}
                >
                  <option value="" disabled>
                    {t("boq.template.selectPlaceholder")}
                  </option>
                  {templateCategories?.map((c) => (
                    <option key={c.id} value={c.id}>
                      {"— ".repeat(c.depth)}
                      {c.name}
                    </option>
                  ))}
                </select>
                <Button onClick={handleApplyTemplate} disabled={!selectedTemplateId || applyingTemplate}>
                  {applyingTemplate ? t("boq.template.applying") : t("boq.template.apply")}
                </Button>
              </div>
            )}
            {templateError ? <ErrorBanner message={templateError} /> : null}
          </CardBody>
        </Card>
      ) : null}

      {showImport ? (
        <Card>
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boq.import.title")}</h2>
          </CardHeader>
          <CardBody className="flex flex-col gap-3">
            <form onSubmit={handleImport} className="flex flex-wrap items-center gap-3">
              <input
                ref={fileInputRef}
                type="file"
                accept=".csv,text/csv"
                onChange={(e: ChangeEvent<HTMLInputElement>) => setImportFile(e.target.files?.[0] ?? null)}
                className="text-sm"
              />
              <Button type="submit" disabled={!importFile || importing}>
                {importing ? t("boq.import.submitting") : t("boq.import.submit")}
              </Button>
            </form>
            {importError ? <ErrorBanner message={importError} /> : null}
            {importResult ? (
              <div className="rounded-md border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                <p>
                  {importResult.created} {t("boq.import.created")} · {importResult.skipped}{" "}
                  {t("boq.import.skipped")}
                </p>
                {importResult.errors.length > 0 ? (
                  <ul className="mt-2 list-disc pl-5 text-xs text-red-600 dark:text-red-400">
                    {importResult.errors.map((e, idx) => (
                      <li key={idx}>
                        {t("boq.import.rowError")} {e.row}: {e.reason}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            ) : null}
          </CardBody>
        </Card>
      ) : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[240px_1fr_260px]">
        {/* Left panel: categories + rooms */}
        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex items-center justify-between">
              <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boq.categories.title")}</h2>
              <button
                type="button"
                onClick={() => setShowAddCategory((v) => !v)}
                className="text-lg leading-none text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100"
                aria-label={t("boq.categories.new.cta")}
              >
                +
              </button>
            </CardHeader>
            {showAddCategory ? (
              <CardBody className="border-b border-zinc-200 dark:border-zinc-800">
                <form onSubmit={handleAddCategory} className="flex flex-col gap-2">
                  <input
                    type="text"
                    required
                    placeholder={t("boq.categories.new.name")}
                    value={newCategoryName}
                    onChange={(e) => setNewCategoryName(e.target.value)}
                    className={INPUT_CLASSES}
                  />
                  <select
                    value={newCategoryParentId}
                    onChange={(e) => setNewCategoryParentId(e.target.value)}
                    className={INPUT_CLASSES}
                  >
                    <option value="">{t("boq.categories.new.root")}</option>
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {"— ".repeat(c.depth)}
                        {c.name}
                      </option>
                    ))}
                  </select>
                  {addCategoryError ? <ErrorBanner message={addCategoryError} /> : null}
                  <Button type="submit" disabled={addingCategory} className="py-1">
                    {addingCategory ? t("boq.categories.new.submitting") : t("boq.categories.new.submit")}
                  </Button>
                </form>
              </CardBody>
            ) : null}
            <CardBody className="flex flex-col gap-0.5 p-2">
              <TreeButton
                label={t("boq.categories.all")}
                value={formatMoney(grandTotal.client_total)}
                active={selectedCategoryId === null}
                depth={0}
                onClick={() => setSelectedCategoryId(null)}
              />
              {categories.length === 0 ? (
                <p className="px-2 py-3 text-xs text-zinc-400">{t("boq.categories.empty")}</p>
              ) : (
                categories.map((c) => (
                  <TreeButton
                    key={c.id}
                    label={c.name}
                    value={formatMoney(
                      computeSubtotal(items.filter((i) => String(i.category_id) === c.id)).client_total,
                    )}
                    active={selectedCategoryId === c.id}
                    depth={c.depth}
                    onClick={() => setSelectedCategoryId(c.id)}
                  />
                ))
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader className="flex items-center justify-between">
              <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boq.rooms.title")}</h2>
              <button
                type="button"
                onClick={() => setShowAddRoom((v) => !v)}
                className="text-lg leading-none text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100"
                aria-label={t("boq.rooms.new.cta")}
              >
                +
              </button>
            </CardHeader>
            {showAddRoom ? (
              <CardBody className="border-b border-zinc-200 dark:border-zinc-800">
                <form onSubmit={handleAddRoom} className="flex flex-col gap-2">
                  <input
                    type="text"
                    required
                    placeholder={t("boq.rooms.new.name")}
                    value={newRoomName}
                    onChange={(e) => setNewRoomName(e.target.value)}
                    className={INPUT_CLASSES}
                  />
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder={t("boq.rooms.new.area")}
                    value={newRoomArea}
                    onChange={(e) => setNewRoomArea(e.target.value)}
                    className={INPUT_CLASSES}
                  />
                  {addRoomError ? <ErrorBanner message={addRoomError} /> : null}
                  <Button type="submit" disabled={addingRoom} className="py-1">
                    {addingRoom ? t("boq.rooms.new.submitting") : t("boq.rooms.new.submit")}
                  </Button>
                </form>
              </CardBody>
            ) : null}
            <CardBody className="flex flex-col gap-0.5 p-2">
              <TreeButton
                label={t("boq.rooms.all")}
                value={formatMoney(grandTotal.client_total)}
                active={selectedRoomId === null}
                depth={0}
                onClick={() => setSelectedRoomId(null)}
              />
              {rooms.length === 0 ? (
                <p className="px-2 py-3 text-xs text-zinc-400">{t("boq.rooms.empty")}</p>
              ) : (
                rooms.map((room) => (
                  <TreeButton
                    key={room.id}
                    label={room.name}
                    value={formatMoney(
                      computeSubtotal(items.filter((i) => String(i.room_id ?? "") === String(room.id))).client_total,
                    )}
                    active={selectedRoomId === String(room.id)}
                    depth={0}
                    onClick={() => setSelectedRoomId(String(room.id))}
                  />
                ))
              )}
            </CardBody>
          </Card>
        </div>

        {/* Center: line item grid */}
        <Card>
          <CardHeader className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boq.items.title")}</h2>
            <Button variant="secondary" className="py-1" onClick={() => setShowAddItem((v) => !v)}>
              {t("boq.add.cta")}
            </Button>
          </CardHeader>

          {showAddItem ? (
            <CardBody className="border-b border-zinc-200 dark:border-zinc-800">
              <form onSubmit={handleAddItem} className="flex flex-wrap items-end gap-3">
                <FormField label={t("boq.add.category")} htmlFor="new-item-category">
                  <select
                    id="new-item-category"
                    required
                    value={newItemCategoryId}
                    onChange={(e) => setNewItemCategoryId(e.target.value)}
                    className={INPUT_CLASSES}
                  >
                    <option value="" disabled>
                      {t("boq.add.categoryPlaceholder")}
                    </option>
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {"— ".repeat(c.depth)}
                        {c.name}
                      </option>
                    ))}
                  </select>
                </FormField>
                <FormField label={t("boq.add.room")} htmlFor="new-item-room">
                  <select
                    id="new-item-room"
                    value={newItemRoomId}
                    onChange={(e) => setNewItemRoomId(e.target.value)}
                    className={INPUT_CLASSES}
                  >
                    <option value="">{t("boq.items.noRoom")}</option>
                    {rooms.map((room) => (
                      <option key={room.id} value={room.id}>
                        {room.name}
                      </option>
                    ))}
                  </select>
                </FormField>
                <FormField label={t("boq.add.name")} htmlFor="new-item-name">
                  <input
                    id="new-item-name"
                    type="text"
                    required
                    value={newItemName}
                    onChange={(e) => setNewItemName(e.target.value)}
                    className={INPUT_CLASSES}
                  />
                </FormField>
                <FormField label={t("boq.add.unit")} htmlFor="new-item-unit">
                  <input
                    id="new-item-unit"
                    type="text"
                    required
                    value={newItemUnit}
                    onChange={(e) => setNewItemUnit(e.target.value)}
                    className={`${INPUT_CLASSES} w-20`}
                  />
                </FormField>
                <FormField label={t("boq.add.quantity")} htmlFor="new-item-quantity">
                  <input
                    id="new-item-quantity"
                    type="number"
                    min="0"
                    step="0.01"
                    required
                    value={newItemQuantity}
                    onChange={(e) => setNewItemQuantity(e.target.value)}
                    className={`${INPUT_CLASSES} w-24`}
                  />
                </FormField>
                <Button type="submit" disabled={addingItem || categories.length === 0}>
                  {addingItem ? t("boq.add.submitting") : t("boq.add.submit")}
                </Button>
              </form>
              {categories.length === 0 ? (
                <p className="mt-2 text-xs text-amber-600 dark:text-amber-400">{t("boq.categories.empty")}</p>
              ) : null}
              {addItemError ? <ErrorBanner message={addItemError} /> : null}
            </CardBody>
          ) : null}

          {selectedItemIds.size > 0 ? (
            <CardBody className="flex flex-wrap items-center gap-3 border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800/40">
              <span className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                {selectedItemIds.size} {t("boq.bulk.selected")}
              </span>
              <select
                value={bulkTargetCategoryId}
                onChange={(e) => setBulkTargetCategoryId(e.target.value)}
                className={INPUT_CLASSES}
              >
                <option value="" disabled>
                  {t("boq.bulk.moveTo")}
                </option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {"— ".repeat(c.depth)}
                    {c.name}
                  </option>
                ))}
              </select>
              <Button
                variant="secondary"
                className="py-1"
                onClick={handleBulkMove}
                disabled={!bulkTargetCategoryId || bulkMoving}
              >
                {t("boq.bulk.apply")}
              </Button>
            </CardBody>
          ) : null}

          <CardBody className="overflow-x-auto p-0">
            {filteredItems.length === 0 ? (
              <EmptyState message={t("boq.items.empty")} />
            ) : (
              <table className="w-full min-w-[900px] border-collapse text-sm">
                <thead>
                  <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                    <th className="w-8 px-2 py-2">
                      <input
                        type="checkbox"
                        checked={filteredItems.length > 0 && filteredItems.every((i) => selectedItemIds.has(String(i.id)))}
                        onChange={toggleSelectAllVisible}
                      />
                    </th>
                    <th className="px-2 py-2">{t("boq.items.columns.name")}</th>
                    <th className="px-2 py-2">{t("boq.items.columns.unit")}</th>
                    <th className="px-2 py-2 text-right">{t("boq.items.columns.quantity")}</th>
                    <th className="px-2 py-2 text-right">{t("boq.items.columns.material")}</th>
                    <th className="px-2 py-2 text-right">{t("boq.items.columns.labor")}</th>
                    <th className="px-2 py-2 text-right">{t("boq.items.columns.other")}</th>
                    <th className="px-2 py-2 text-right">{t("boq.items.columns.clientPrice")}</th>
                    <th className="px-2 py-2 text-right">{t("boq.items.columns.directCost")}</th>
                    <th className="px-2 py-2 text-right">{t("boq.items.columns.clientTotal")}</th>
                    <th className="px-2 py-2">{t("boq.items.columns.room")}</th>
                    <th className="px-2 py-2">{t("boq.items.columns.actions")}</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredItems.map((item, rowIndex) => {
                    const totals = computeItemTotals(item);
                    return (
                      <tr
                        key={item.id}
                        className="border-b border-zinc-100 last:border-0 hover:bg-zinc-50 dark:border-zinc-800/60 dark:hover:bg-zinc-800/30"
                      >
                        <td className="px-2 py-1 align-top">
                          <input
                            type="checkbox"
                            checked={selectedItemIds.has(String(item.id))}
                            onChange={() => toggleSelected(item.id)}
                          />
                        </td>
                        <td className="px-1 py-1 align-top">
                          <input
                            type="text"
                            value={item.name}
                            data-row={rowIndex}
                            data-col="name"
                            onChange={(e) => setLocalItemField(item.id, "name", e.target.value)}
                            onBlur={() => commitItemField(item, "name")}
                            onKeyDown={handleCellKeyDown}
                            className={CELL_INPUT_CLASSES}
                          />
                          {selectedCategoryId === null ? (
                            <p className="px-1.5 text-xs text-zinc-400">
                              {categoryNameById.get(String(item.category_id)) ?? t("common.na")}
                            </p>
                          ) : null}
                        </td>
                        <td className="px-1 py-1 align-top">
                          <input
                            type="text"
                            value={item.unit}
                            data-row={rowIndex}
                            data-col="unit"
                            onChange={(e) => setLocalItemField(item.id, "unit", e.target.value)}
                            onBlur={() => commitItemField(item, "unit")}
                            onKeyDown={handleCellKeyDown}
                            className={`${CELL_INPUT_CLASSES} w-16`}
                          />
                        </td>
                        <NumericCell
                          value={item.quantity}
                          rowIndex={rowIndex}
                          col="quantity"
                          onChange={(v) => setLocalItemField(item.id, "quantity", v)}
                          onCommit={() => commitItemField(item, "quantity")}
                          onKeyDown={handleCellKeyDown}
                        />
                        <NumericCell
                          value={item.material_unit_cost}
                          rowIndex={rowIndex}
                          col="material_unit_cost"
                          onChange={(v) => setLocalItemField(item.id, "material_unit_cost", v)}
                          onCommit={() => commitItemField(item, "material_unit_cost")}
                          onKeyDown={handleCellKeyDown}
                        />
                        <NumericCell
                          value={item.labor_unit_cost}
                          rowIndex={rowIndex}
                          col="labor_unit_cost"
                          onChange={(v) => setLocalItemField(item.id, "labor_unit_cost", v)}
                          onCommit={() => commitItemField(item, "labor_unit_cost")}
                          onKeyDown={handleCellKeyDown}
                        />
                        <NumericCell
                          value={item.other_unit_cost}
                          rowIndex={rowIndex}
                          col="other_unit_cost"
                          onChange={(v) => setLocalItemField(item.id, "other_unit_cost", v)}
                          onCommit={() => commitItemField(item, "other_unit_cost")}
                          onKeyDown={handleCellKeyDown}
                        />
                        <NumericCell
                          value={item.client_unit_price}
                          rowIndex={rowIndex}
                          col="client_unit_price"
                          onChange={(v) => setLocalItemField(item.id, "client_unit_price", v)}
                          onCommit={() => commitItemField(item, "client_unit_price")}
                          onKeyDown={handleCellKeyDown}
                        />
                        <td className="px-2 py-1 text-right align-top font-medium text-zinc-700 dark:text-zinc-300">
                          {formatMoney(totals.direct_cost)}
                        </td>
                        <td className="px-2 py-1 text-right align-top font-medium text-zinc-900 dark:text-zinc-100">
                          {formatMoney(totals.client_total)}
                        </td>
                        <td className="px-1 py-1 align-top">
                          <select
                            value={item.room_id === null || item.room_id === undefined ? "" : String(item.room_id)}
                            onChange={(e) => handleRoomChange(item, e.target.value)}
                            className={`${CELL_INPUT_CLASSES} w-24`}
                          >
                            <option value="">{t("boq.items.noRoom")}</option>
                            {rooms.map((room) => (
                              <option key={room.id} value={room.id}>
                                {room.name}
                              </option>
                            ))}
                          </select>
                        </td>
                        <td className="whitespace-nowrap px-2 py-1 align-top">
                          <div className="flex items-center gap-2">
                            <button
                              type="button"
                              onClick={() => handleDuplicate(item)}
                              className="text-xs font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100"
                            >
                              {t("boq.items.duplicate")}
                            </button>
                            <button
                              type="button"
                              onClick={() => handleArchive(item)}
                              className="text-xs font-medium text-red-500 hover:text-red-700"
                            >
                              {t("boq.items.archive")}
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </CardBody>
        </Card>

        {/* Right panel: totals */}
        <Card className="h-fit">
          <CardHeader>
            <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boq.categories.title")}</h2>
          </CardHeader>
          <CardBody className="flex flex-col gap-2 text-sm">
            {categories.map((c) => {
              const subtotal = computeSubtotal(items.filter((i) => String(i.category_id) === c.id));
              return (
                <div key={c.id} className="flex items-baseline justify-between gap-2">
                  <span
                    className="truncate text-zinc-600 dark:text-zinc-300"
                    style={{ paddingInlineStart: `${c.depth * 10}px` }}
                  >
                    {c.name}
                  </span>
                  <span className="shrink-0 text-right text-xs text-zinc-400">
                    {formatMoney(subtotal.direct_cost)}
                  </span>
                </div>
              );
            })}
            {roomSubtotal ? (
              <div className="mt-2 border-t border-zinc-200 pt-2 dark:border-zinc-800">
                <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{t("boq.rooms.subtotal")}</p>
                <div className="mt-1 flex items-baseline justify-between">
                  <span className="text-zinc-600 dark:text-zinc-300">{t("boq.directCost")}</span>
                  <span>{formatMoney(roomSubtotal.direct_cost)}</span>
                </div>
                <div className="flex items-baseline justify-between">
                  <span className="text-zinc-600 dark:text-zinc-300">{t("boq.clientTotal")}</span>
                  <span>{formatMoney(roomSubtotal.client_total)}</span>
                </div>
              </div>
            ) : null}
          </CardBody>
          <CardBody className="border-t border-zinc-200 dark:border-zinc-800">
            <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{t("boq.grandTotal")}</p>
            <div className="mt-1 flex items-baseline justify-between">
              <span className="text-sm text-zinc-600 dark:text-zinc-300">{t("boq.directCost")}</span>
              <span className="text-base font-semibold text-zinc-900 dark:text-zinc-50">
                {formatMoney(grandTotal.direct_cost)}
              </span>
            </div>
            <div className="mt-1 flex items-baseline justify-between">
              <span className="text-sm text-zinc-600 dark:text-zinc-300">{t("boq.clientTotal")}</span>
              <span className="text-base font-semibold text-zinc-900 dark:text-zinc-50">
                {formatMoney(grandTotal.client_total)}
              </span>
            </div>
          </CardBody>
        </Card>
      </div>

      {/* Pricing (S08) — project-level markup/fee/discount layers on top of the line-item
          pricing above. Rendered as a sibling section within this same "BOQ & Pricing" tab
          rather than a separate route, per PROJECT_CONTEXT.md Sprint 3 UX guidance. */}
      <PricingPanel projectId={projectId} />
    </div>
  );
}

function TreeButton({
  label,
  value,
  active,
  depth,
  onClick,
}: {
  label: string;
  value: string;
  active: boolean;
  depth: number;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      style={{ paddingInlineStart: `${8 + depth * 12}px` }}
      className={`flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors ${
        active
          ? "bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900"
          : "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
      }`}
    >
      <span className="truncate">{label}</span>
      <span className={`shrink-0 text-xs ${active ? "text-white/80 dark:text-zinc-900/70" : "text-zinc-400"}`}>
        {value}
      </span>
    </button>
  );
}

function NumericCell({
  value,
  rowIndex,
  col,
  onChange,
  onCommit,
  onKeyDown,
}: {
  value: number | string;
  rowIndex: number;
  col: string;
  onChange: (value: string) => void;
  onCommit: () => void;
  onKeyDown: (e: KeyboardEvent<HTMLInputElement>) => void;
}) {
  return (
    <td className="px-1 py-1 align-top">
      <input
        type="number"
        min="0"
        step="0.01"
        value={value}
        data-row={rowIndex}
        data-col={col}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onCommit}
        onKeyDown={onKeyDown}
        className={`${CELL_INPUT_CLASSES} text-right`}
      />
    </td>
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
