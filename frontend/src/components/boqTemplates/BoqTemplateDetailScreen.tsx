"use client";

/**
 * BOQ Master Catalog + Standard Templates — admin template detail (org or platform mount):
 * header edit, version lifecycle (draft/publish), and item management (only while a version is
 * a draft — enforced server-side, mirrored here by simply not rendering the add/remove controls
 * for a non-draft version rather than disabling them, since there is nothing useful to retry).
 */

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import {
  activateBoqTemplate,
  createBoqTemplateVersion,
  createBoqTemplateVersionItem,
  deactivateBoqTemplate,
  deleteBoqTemplateVersionItem,
  getBoqTemplate,
  getBoqTemplateUsage,
  getBoqTemplateVersion,
  listBoqTemplateVersions,
  publishBoqTemplateVersion,
  searchBoqCatalog,
  updateBoqTemplate,
} from "@/lib/api/resources/boqTemplates";
import {
  BOQ_TEMPLATE_TYPES,
  type BoqCatalogItemSummary,
  type BoqTemplate,
  type BoqTemplateFinishingLevel,
  type BoqTemplateType,
  type BoqTemplateUsage,
  type BoqTemplateVersion,
} from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";

const INPUT_CLASSES =
  "w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

const FINISHING_LEVELS: BoqTemplateFinishingLevel[] = ["BASIC", "STANDARD", "PREMIUM", "LUXURY"];

export function BoqTemplateDetailScreen({
  platform,
  basePath,
  templateId,
}: {
  platform: boolean;
  basePath: string;
  templateId: string;
}) {
  const { t } = useLocale();
  const [template, setTemplate] = useState<BoqTemplate | null>(null);
  const [versions, setVersions] = useState<BoqTemplateVersion[]>([]);
  const [usage, setUsage] = useState<BoqTemplateUsage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Header edit form state.
  const [name, setName] = useState("");
  const [nameEn, setNameEn] = useState("");
  const [nameAr, setNameAr] = useState("");
  const [description, setDescription] = useState("");
  const [templateType, setTemplateType] = useState<BoqTemplateType>("CUSTOM");
  const [finishingLevel, setFinishingLevel] = useState<BoqTemplateFinishingLevel | "">("");
  const [savingHeader, setSavingHeader] = useState(false);
  const [togglingActive, setTogglingActive] = useState(false);

  // Expanded version + its items.
  const [expandedVersion, setExpandedVersion] = useState<BoqTemplateVersion | null>(null);
  const [loadingVersionItems, setLoadingVersionItems] = useState(false);
  const [creatingVersion, setCreatingVersion] = useState(false);
  const [copyFromVersionId, setCopyFromVersionId] = useState("");
  const [publishingVersionId, setPublishingVersionId] = useState<string | null>(null);

  // Add-item form.
  const [catalogQuery, setCatalogQuery] = useState("");
  const [catalogResults, setCatalogResults] = useState<BoqCatalogItemSummary[] | null>(null);
  const [searchingCatalog, setSearchingCatalog] = useState(false);
  const [selectedCatalogItem, setSelectedCatalogItem] = useState<BoqCatalogItemSummary | null>(null);
  const [newItemQuantity, setNewItemQuantity] = useState("");
  const [newItemRequired, setNewItemRequired] = useState(true);
  const [addingItem, setAddingItem] = useState(false);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      const [tpl, versionList, usageResult] = await Promise.all([
        getBoqTemplate(templateId, platform),
        listBoqTemplateVersions(templateId, platform),
        getBoqTemplateUsage(templateId, platform),
      ]);
      setTemplate(tpl);
      setVersions(versionList);
      setUsage(usageResult);
      setName(tpl.name);
      setNameEn(tpl.name_en ?? "");
      setNameAr(tpl.name_ar ?? "");
      setDescription(tpl.description ?? "");
      setTemplateType(tpl.template_type);
      setFinishingLevel(tpl.finishing_level ?? "");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [templateId]);

  async function handleSaveHeader(e: FormEvent) {
    e.preventDefault();
    setSavingHeader(true);
    setError(null);
    try {
      const updated = await updateBoqTemplate(
        templateId,
        {
          name,
          name_en: nameEn || undefined,
          name_ar: nameAr || undefined,
          description: description || undefined,
          template_type: templateType,
          finishing_level: finishingLevel || undefined,
        },
        platform,
      );
      setTemplate(updated);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSavingHeader(false);
    }
  }

  async function handleToggleActive() {
    if (!template) return;
    setTogglingActive(true);
    setError(null);
    try {
      const updated = template.is_active
        ? await deactivateBoqTemplate(templateId, platform)
        : await activateBoqTemplate(templateId, platform);
      setTemplate(updated);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setTogglingActive(false);
    }
  }

  async function handleCreateVersion() {
    setCreatingVersion(true);
    setError(null);
    try {
      const created = await createBoqTemplateVersion(
        templateId,
        copyFromVersionId || undefined,
        platform,
      );
      setVersions((prev) => [created, ...prev]);
      setCopyFromVersionId("");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setCreatingVersion(false);
    }
  }

  async function handleToggleExpandVersion(version: BoqTemplateVersion) {
    if (expandedVersion?.id === version.id) {
      setExpandedVersion(null);
      return;
    }
    setLoadingVersionItems(true);
    setError(null);
    try {
      const full = await getBoqTemplateVersion(templateId, version.id, platform);
      setExpandedVersion(full);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setLoadingVersionItems(false);
    }
  }

  async function refreshExpandedVersion() {
    if (!expandedVersion) return;
    const full = await getBoqTemplateVersion(templateId, expandedVersion.id, platform);
    setExpandedVersion(full);
    setVersions((prev) => prev.map((v) => (v.id === full.id ? full : v)));
  }

  async function handlePublish(version: BoqTemplateVersion) {
    setPublishingVersionId(String(version.id));
    setError(null);
    try {
      const published = await publishBoqTemplateVersion(templateId, version.id, platform);
      await load();
      if (expandedVersion?.id === version.id) {
        setExpandedVersion({ ...expandedVersion, status: published.status, published_at: published.published_at });
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setPublishingVersionId(null);
    }
  }

  async function handleSearchCatalog(e: FormEvent) {
    e.preventDefault();
    if (!catalogQuery.trim()) return;
    setSearchingCatalog(true);
    setError(null);
    try {
      setCatalogResults(await searchBoqCatalog(catalogQuery, platform));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setSearchingCatalog(false);
    }
  }

  async function handleAddItem() {
    if (!expandedVersion || !selectedCatalogItem) return;
    setAddingItem(true);
    setError(null);
    try {
      await createBoqTemplateVersionItem(
        templateId,
        expandedVersion.id,
        {
          catalog_item_id: selectedCatalogItem.id,
          default_quantity: newItemQuantity || undefined,
          is_required: newItemRequired,
          is_optional: !newItemRequired,
        },
        platform,
      );
      await refreshExpandedVersion();
      setSelectedCatalogItem(null);
      setNewItemQuantity("");
      setCatalogQuery("");
      setCatalogResults(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setAddingItem(false);
    }
  }

  async function handleDeleteItem(itemId: string) {
    setError(null);
    try {
      await deleteBoqTemplateVersionItem(itemId, platform);
      await refreshExpandedVersion();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
    }
  }

  if (loading) return <LoadingScreen label={t("common.loading")} />;
  if (error && !template) return <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} />;
  if (!template) return null;

  // A system template (organization_id = null) can only be mutated through the platform mount —
  // see BoqTemplateAdminController's resolveTemplateForMutation(). The org screen still shows it
  // (reads merge system + org templates) but every mutation control is hidden here instead of
  // left clickable-but-silently-404ing.
  const readOnly = !platform && template.is_system;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link href={basePath} className="text-sm text-amber-600 hover:underline dark:text-amber-400">
          ← {t("boqAdmin.back")}
        </Link>
        <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{template.name}</h1>
          <div className="flex items-center gap-2">
            {template.is_system ? <Badge tone="blue">{t("boqAdmin.isSystem")}</Badge> : null}
            {!template.is_active ? <Badge tone="red">{t("boqAdmin.inactive")}</Badge> : null}
            {!readOnly ? (
              <Button variant="secondary" onClick={handleToggleActive} disabled={togglingActive}>
                {template.is_active ? t("boqAdmin.header.deactivate") : t("boqAdmin.header.activate")}
              </Button>
            ) : null}
          </div>
        </div>
        {readOnly ? (
          <p className="mt-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800 dark:border-blue-900/50 dark:bg-blue-950/40 dark:text-blue-300">
            {t("boqAdmin.systemReadOnly")}
          </p>
        ) : null}
        {usage ? (
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            {t("boqAdmin.header.usage").replace("{count}", String(usage.applications_count))}
          </p>
        ) : null}
      </div>

      {error ? <ErrorBanner message={error} /> : null}

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boqAdmin.header.heading")}</h2>
        </CardHeader>
        <CardBody>
          <form onSubmit={handleSaveHeader} className="flex flex-col gap-4">
          <fieldset disabled={readOnly} className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label={t("boqAdmin.form.name")}>
                <input className={INPUT_CLASSES} value={name} onChange={(e) => setName(e.target.value)} required />
              </Field>
              <Field label={t("boqAdmin.form.nameEn")}>
                <input className={INPUT_CLASSES} value={nameEn} onChange={(e) => setNameEn(e.target.value)} />
              </Field>
              <Field label={t("boqAdmin.form.nameAr")}>
                <input className={INPUT_CLASSES} dir="rtl" value={nameAr} onChange={(e) => setNameAr(e.target.value)} />
              </Field>
              <Field label={t("boqAdmin.form.type")}>
                <select
                  className={INPUT_CLASSES}
                  value={templateType}
                  onChange={(e) => setTemplateType(e.target.value as BoqTemplateType)}
                >
                  {BOQ_TEMPLATE_TYPES.map((type) => (
                    <option key={type} value={type}>
                      {type}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={t("boqAdmin.form.finishingLevel")}>
                <select
                  className={INPUT_CLASSES}
                  value={finishingLevel}
                  onChange={(e) => setFinishingLevel(e.target.value as BoqTemplateFinishingLevel | "")}
                >
                  <option value="">{t("boqAdmin.form.finishingLevelNone")}</option>
                  {FINISHING_LEVELS.map((level) => (
                    <option key={level} value={level}>
                      {level}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <Field label={t("boqAdmin.form.description")}>
              <textarea
                className={INPUT_CLASSES}
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
            </Field>
            {!readOnly ? (
              <div>
                <Button type="submit" disabled={savingHeader}>
                  {savingHeader ? t("boqAdmin.form.saving") : t("boqAdmin.form.save")}
                </Button>
              </div>
            ) : null}
          </fieldset>
          </form>
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("boqAdmin.versions.heading")}</h2>
        </CardHeader>
        <CardBody className="flex flex-col gap-4">
          {!readOnly ? (
            <div className="flex flex-wrap items-center gap-2">
              <select
                className={`${INPUT_CLASSES} w-auto`}
                value={copyFromVersionId}
                onChange={(e) => setCopyFromVersionId(e.target.value)}
              >
                <option value="">{t("boqAdmin.versions.copyFromNone")}</option>
                {versions.map((v) => (
                  <option key={v.id} value={v.id}>
                    {t("boqAdmin.versions.copyFrom")} v{v.version_number}
                  </option>
                ))}
              </select>
              <Button onClick={handleCreateVersion} disabled={creatingVersion}>
                {t("boqAdmin.versions.newDraft")}
              </Button>
            </div>
          ) : null}

          <div className="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800">
            {versions.map((version) => (
              <div key={version.id} className="py-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-zinc-900 dark:text-zinc-50">v{version.version_number}</span>
                    <Badge tone={version.status === "published" ? "green" : version.status === "draft" ? "neutral" : "amber"}>
                      {version.status}
                    </Badge>
                    <span className="text-xs text-zinc-500 dark:text-zinc-400">
                      {t("boqAdmin.versions.itemsCount").replace("{count}", String(version.items_count ?? 0))}
                    </span>
                  </div>
                  <div className="flex items-center gap-2">
                    {version.status === "draft" && !readOnly ? (
                      <Button
                        variant="secondary"
                        className="py-1"
                        onClick={() => handlePublish(version)}
                        disabled={publishingVersionId === String(version.id)}
                      >
                        {publishingVersionId === String(version.id) ? t("boqAdmin.versions.publishing") : t("boqAdmin.versions.publish")}
                      </Button>
                    ) : null}
                    <Button variant="secondary" className="py-1" onClick={() => handleToggleExpandVersion(version)}>
                      {expandedVersion?.id === version.id ? t("boqAdmin.versions.hideItems") : t("boqAdmin.versions.viewItems")}
                    </Button>
                  </div>
                </div>

                {expandedVersion?.id === version.id ? (
                  <div className="mt-3 rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
                    {loadingVersionItems ? (
                      <LoadingScreen label={t("common.loading")} />
                    ) : (
                      <>
                        <h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                          {t("boqAdmin.items.heading")}
                        </h3>
                        {!expandedVersion.items || expandedVersion.items.length === 0 ? (
                          <EmptyState message={t("boqAdmin.items.empty")} />
                        ) : (
                          <div className="mt-2 flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800">
                            {expandedVersion.items.map((item) => (
                              <div key={item.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <div>
                                  <span className="text-zinc-900 dark:text-zinc-50">{item.catalog_item?.name}</span>
                                  <span className="ms-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    {item.default_quantity ?? "—"} {item.default_unit?.code ?? ""} ·{" "}
                                    {item.is_required ? t("boqAdmin.items.required") : t("boqAdmin.items.optional")}
                                  </span>
                                </div>
                                {version.status === "draft" && !readOnly ? (
                                  <Button
                                    variant="secondary"
                                    className="py-1 text-xs"
                                    onClick={() => handleDeleteItem(String(item.id))}
                                  >
                                    {t("boqAdmin.items.delete")}
                                  </Button>
                                ) : null}
                              </div>
                            ))}
                          </div>
                        )}

                        {version.status === "draft" && !readOnly ? (
                          <div className="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            <h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                              {t("boqAdmin.items.addHeading")}
                            </h3>
                            <form onSubmit={handleSearchCatalog} className="mt-2 flex flex-wrap items-center gap-2">
                              <input
                                className={`${INPUT_CLASSES} w-64`}
                                placeholder={t("boqAdmin.items.searchPlaceholder")}
                                value={catalogQuery}
                                onChange={(e) => setCatalogQuery(e.target.value)}
                              />
                              <Button type="submit" variant="secondary" disabled={searchingCatalog}>
                                {searchingCatalog ? t("boqAdmin.items.searching") : t("boqAdmin.items.search")}
                              </Button>
                            </form>
                            {catalogResults ? (
                              catalogResults.length === 0 ? (
                                <EmptyState message={t("boqAdmin.items.noResults")} />
                              ) : (
                                <div className="mt-2 flex flex-wrap gap-2">
                                  {catalogResults.map((item) => (
                                    <button
                                      key={item.id}
                                      type="button"
                                      onClick={() => setSelectedCatalogItem(item)}
                                      className={`rounded-md border px-2 py-1 text-xs ${
                                        selectedCatalogItem?.id === item.id
                                          ? "border-amber-500 bg-amber-50 dark:bg-amber-950/30"
                                          : "border-zinc-200 dark:border-zinc-800"
                                      }`}
                                    >
                                      {item.name}
                                    </button>
                                  ))}
                                </div>
                              )
                            ) : null}
                            {selectedCatalogItem ? (
                              <div className="mt-3 flex flex-wrap items-center gap-2">
                                <span className="text-xs text-zinc-500 dark:text-zinc-400">
                                  {t("boqAdmin.items.selected")}: {selectedCatalogItem.name}
                                </span>
                                <input
                                  type="number"
                                  step="0.01"
                                  placeholder={t("boqAdmin.items.quantity")}
                                  className={`${INPUT_CLASSES} w-24`}
                                  value={newItemQuantity}
                                  onChange={(e) => setNewItemQuantity(e.target.value)}
                                />
                                <label className="flex items-center gap-1 text-xs text-zinc-600 dark:text-zinc-400">
                                  <input
                                    type="checkbox"
                                    checked={newItemRequired}
                                    onChange={(e) => setNewItemRequired(e.target.checked)}
                                  />
                                  {t("boqAdmin.items.required")}
                                </label>
                                <Button onClick={handleAddItem} disabled={addingItem} className="py-1">
                                  {addingItem ? t("boqAdmin.items.adding") : t("boqAdmin.items.add")}
                                </Button>
                              </div>
                            ) : null}
                          </div>
                        ) : (
                          <p className="mt-3 text-xs text-zinc-400">{t("boqAdmin.items.readOnlyNotice")}</p>
                        )}
                      </>
                    )}
                  </div>
                ) : null}
              </div>
            ))}
          </div>
        </CardBody>
      </Card>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-sm">
      <span className="font-medium text-zinc-700 dark:text-zinc-300">{label}</span>
      {children}
    </label>
  );
}
