"use client";

/**
 * New-project wizard (docs/SIMPLIFICATION_REVIEW.md P2.1) — replaces the old inline form that
 * dead-ended on day one (it needed an existing client, then sent people to an empty BOQ).
 *
 *   1. Who is it for?   pick a client, or type a new one (name + phone) right here
 *   2. What's the job?  which place (one of the client's properties, or a new one with type +
 *                       area), project name, start/target dates (all optional except the name)
 *   3. Start from       a ready template (Standard full finishing suggested) or empty
 *
 * Then lands on the project's Items & Prices with the template already applied.
 *
 * It chains the existing endpoints (POST /clients, /clients/{id}/properties, /projects, then the
 * template preview → commit) rather than a bespoke all-in-one endpoint, so every step keeps its
 * existing validation and audit trail. Each created id is remembered, so if a later step fails,
 * "Try again" resumes from there instead of creating a duplicate client or project; if only the
 * template step fails, the project still exists and the user can continue to it.
 *
 * ?client_id= pre-selects a client (the marketplace-inquiry hand-off uses it).
 */

import { Suspense, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { createClient, createProperty, getClient, listClients } from "@/lib/api/resources/clients";
import { createProject } from "@/lib/api/resources/projects";
import { commitBoqTemplates, listBoqTemplates, previewBoqTemplates } from "@/lib/api/resources/boqTemplates";
import type { BoqTemplate, Client, Property } from "@/lib/api/types";
import { defaultCommitItems } from "@/lib/boq/templateApply";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { finishingLevelLabel, localizedDescription, localizedName } from "@/lib/i18n/localizedContent";
import { Button } from "@/components/ui/Button";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";

const INPUT =
  "w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

const PROPERTY_TYPES = ["apartment", "villa", "office", "retail", "other"] as const;
const LEVEL_ORDER = ["STANDARD", "PREMIUM", "LUXURY"];
/** Whole-job starting points; room/trade/package templates are add-ons applied later in the BOQ. */
const OTHER_START_TYPES = ["PARTIAL_FINISHING", "RENOVATION"];
const EMPTY = "empty";
const NEW_PLACE = "new";

type Step = 1 | 2 | 3;

export default function NewProjectPage() {
  return (
    <Suspense fallback={<LoadingScreen label="…" />}>
      <Wizard />
    </Suspense>
  );
}

function Wizard() {
  const { t, locale } = useLocale();
  const router = useRouter();
  const searchParams = useSearchParams();

  const [step, setStep] = useState<Step>(1);

  // Step 1
  const [clientMode, setClientMode] = useState<"existing" | "new">("existing");
  const [clients, setClients] = useState<Client[] | null>(null);
  const [clientQuery, setClientQuery] = useState("");
  const prefillId = searchParams.get("client_id");
  const [clientId, setClientId] = useState(prefillId ?? "");
  const [newClientName, setNewClientName] = useState("");
  const [newClientPhone, setNewClientPhone] = useState("");

  // Step 2 — `placeChoice` is an existing property id, or NEW_PLACE.
  // Tagged with the client they belong to, so switching clients never shows stale places.
  const [loadedProperties, setLoadedProperties] = useState<{ clientId: string; list: Property[] } | null>(null);
  const [placeChoice, setPlaceChoice] = useState(NEW_PLACE);
  const [startDate, setStartDate] = useState("");
  const [targetEndDate, setTargetEndDate] = useState("");
  const [propertyType, setPropertyType] = useState("");
  const [area, setArea] = useState("");
  const [projectName, setProjectName] = useState("");
  const [nameTouched, setNameTouched] = useState(false);

  // Step 3
  const [templates, setTemplates] = useState<BoqTemplate[] | null>(null);
  const [templateChoice, setTemplateChoice] = useState<string>("");

  // Submission — ids created so far, so a retry resumes rather than duplicating.
  const [created, setCreated] = useState<{ clientId?: string; propertyId?: string; projectId?: string }>({});
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [templateFailed, setTemplateFailed] = useState(false);

  // Client list (search is server-side; first page is enough to pick from).
  useEffect(() => {
    let cancelled = false;
    const timer = setTimeout(() => {
      listClients(clientQuery ? { q: clientQuery } : {})
        .then((r) => {
          if (cancelled) return;
          setClients(r.data);
          // A brand-new company has no clients yet — go straight to "new client".
          if (!clientQuery && r.data.length === 0 && !prefillId) setClientMode("new");
        })
        .catch(() => !cancelled && setClients([]));
    }, clientQuery ? 250 : 0);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [clientQuery, prefillId]);

  // ?client_id= may point past the first page of the list — load it so it shows as selected.
  const [prefilled, setPrefilled] = useState<Client | null>(null);
  useEffect(() => {
    if (!prefillId) return;
    getClient(prefillId)
      .then(setPrefilled)
      .catch(() => setClientId(""));
  }, [prefillId]);

  // An existing client's places, so a returning client doesn't get a duplicate property.
  useEffect(() => {
    if (clientMode !== "existing" || !clientId) return;
    let cancelled = false;
    getClient(clientId)
      .then((client) => {
        if (cancelled) return;
        const list = client.properties ?? [];
        setLoadedProperties({ clientId, list });
        // One saved place is almost certainly the one; with several, make them choose.
        setPlaceChoice(list.length === 1 ? String(list[0].id) : NEW_PLACE);
      })
      .catch(() => !cancelled && setLoadedProperties({ clientId, list: [] }));
    return () => {
      cancelled = true;
    };
  }, [clientMode, clientId]);
  const clientProperties =
    clientMode === "existing" && loadedProperties?.clientId === clientId ? loadedProperties.list : null;

  useEffect(() => {
    listBoqTemplates()
      .then((all) => {
        const usable = all.filter((tpl) => tpl.is_active && tpl.active_version);
        setTemplates(usable);
        const standard = usable.find((tpl) => tpl.template_type === "FULL_FINISHING" && tpl.finishing_level === "STANDARD");
        setTemplateChoice(String(standard?.id ?? usable.find((tpl) => tpl.template_type === "FULL_FINISHING")?.id ?? EMPTY));
      })
      .catch(() => {
        setTemplates([]);
        setTemplateChoice(EMPTY);
      });
  }, []);

  const clientOptions =
    prefilled && clients && !clients.some((c) => String(c.id) === String(prefilled.id)) && !clientQuery
      ? [prefilled, ...clients]
      : clients;
  const selectedClient = clientOptions?.find((c) => String(c.id) === clientId) ?? null;
  const clientName = clientMode === "new" ? newClientName.trim() : selectedClient?.name ?? "";

  // Suggested project name until the user types their own.
  const chosenProperty = clientProperties?.find((prop) => String(prop.id) === placeChoice) ?? null;
  // A choice that isn't one of the current client's places means "a new place".
  const effectivePlace = chosenProperty ? placeChoice : NEW_PLACE;
  const placeType = chosenProperty ? chosenProperty.type ?? "" : propertyType;
  const suggestedName = useMemo(() => {
    if (!clientName) return "";
    const where = chosenProperty?.compound || (placeType ? t(`property.type.${placeType}` as TranslationKey) : "");
    return where ? `${clientName} — ${where}` : clientName;
  }, [clientName, chosenProperty, placeType, t]);
  const effectiveName = nameTouched ? projectName : suggestedName;

  const fullFinishing = (templates ?? [])
    .filter((tpl) => tpl.template_type === "FULL_FINISHING")
    .sort((a, b) => LEVEL_ORDER.indexOf(a.finishing_level ?? "") - LEVEL_ORDER.indexOf(b.finishing_level ?? ""));
  const otherStarts = (templates ?? []).filter((tpl) => OTHER_START_TYPES.includes(tpl.template_type));

  const step1Valid = clientMode === "new" ? newClientName.trim().length > 0 : Boolean(clientId);
  const step2Valid =
    effectiveName.trim().length > 0 &&
    (area === "" || Number(area) > 0) &&
    (!startDate || !targetEndDate || targetEndDate >= startDate);

  async function handleCreate() {
    setSubmitting(true);
    setError(null);
    setTemplateFailed(false);
    const done = { ...created };
    try {
      if (!done.clientId) {
        done.clientId =
          clientMode === "new"
            ? String((await createClient({ name: newClientName.trim(), phone: newClientPhone.trim() || undefined })).id)
            : clientId;
        setCreated({ ...done });
      }
      if (!done.propertyId && chosenProperty) {
        done.propertyId = String(chosenProperty.id);
      } else if (!done.propertyId && propertyType) {
        done.propertyId = String(
          (await createProperty(done.clientId, { type: propertyType, area_m2: area ? Number(area) : undefined })).id,
        );
        setCreated({ ...done });
      }
      if (!done.projectId) {
        done.projectId = String(
          (
            await createProject({
              client_id: done.clientId,
              property_id: done.propertyId,
              name: effectiveName.trim(),
              start_date: startDate || undefined,
              target_end_date: targetEndDate || undefined,
            })
          ).id,
        );
        setCreated({ ...done });
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("common.unknownError"));
      setSubmitting(false);
      return;
    }

    const template = templates?.find((tpl) => String(tpl.id) === templateChoice);
    if (template?.active_version) {
      try {
        const preview = await previewBoqTemplates(done.projectId, [{ template_version_id: template.active_version.id }]);
        const items = defaultCommitItems(preview, locale);
        if (items.length > 0) await commitBoqTemplates(done.projectId, items, locale);
      } catch {
        // The project exists; only the template didn't apply. Let them continue anyway.
        setTemplateFailed(true);
        setSubmitting(false);
        return;
      }
    }

    router.push(`/projects/${done.projectId}/boq`);
  }

  const steps: { n: Step; label: TranslationKey }[] = [
    { n: 1, label: "wizard.step1.short" },
    { n: 2, label: "wizard.step2.short" },
    { n: 3, label: "wizard.step3.short" },
  ];

  return (
    <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
      <div>
        <Link href="/projects" className="text-sm text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100">
          ← {t("nav.projects")}
        </Link>
        <h1 className="mt-2 text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("projects.new.title")}</h1>
      </div>

      <ol className="flex items-center gap-2 text-sm" aria-label={t("wizard.progress")}>
        {steps.map(({ n, label }, i) => (
          <li key={n} className="flex items-center gap-2">
            {i > 0 ? <span aria-hidden="true" className="h-px w-6 bg-zinc-300 dark:bg-zinc-700" /> : null}
            <span
              aria-current={step === n ? "step" : undefined}
              className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold ${
                step === n
                  ? "bg-zinc-950 text-white dark:bg-white dark:text-zinc-950"
                  : step > n
                    ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                    : "bg-zinc-200 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
              }`}
            >
              {step > n ? "✓" : new Intl.NumberFormat(locale === "ar" ? "ar-EG" : "en-EG").format(n)}
            </span>
            <span className={step === n ? "font-medium text-zinc-900 dark:text-zinc-50" : "text-zinc-500 dark:text-zinc-400"}>
              {t(label)}
            </span>
          </li>
        ))}
      </ol>

      <div className="rounded-lg border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        {step === 1 ? (
          <fieldset className="flex flex-col gap-4">
            <legend className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("wizard.step1.title")}</legend>
            <div className="flex gap-2" role="radiogroup">
              {(["existing", "new"] as const).map((mode) => (
                <button
                  key={mode}
                  type="button"
                  role="radio"
                  aria-checked={clientMode === mode}
                  onClick={() => setClientMode(mode)}
                  className={`rounded-md border px-3 py-2 text-sm font-medium ${
                    clientMode === mode
                      ? "border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-950"
                      : "border-zinc-300 text-zinc-700 hover:border-zinc-500 dark:border-zinc-700 dark:text-zinc-200"
                  }`}
                >
                  {t(mode === "existing" ? "wizard.step1.existing" : "wizard.step1.new")}
                </button>
              ))}
            </div>

            {clientMode === "existing" ? (
              <div className="flex flex-col gap-2">
                <label htmlFor="client-search" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                  {t("wizard.step1.search")}
                </label>
                <input
                  id="client-search"
                  type="search"
                  value={clientQuery}
                  onChange={(e) => setClientQuery(e.target.value)}
                  className={INPUT}
                />
                {clientOptions === null ? (
                  <p className="text-sm text-zinc-500">{t("common.loading")}</p>
                ) : clientOptions.length === 0 ? (
                  <p className="text-sm text-zinc-500">{t("wizard.step1.noMatches")}</p>
                ) : (
                  <ul className="max-h-64 divide-y divide-zinc-100 overflow-y-auto rounded-md border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    {clientOptions.map((client) => (
                      <li key={client.id}>
                        <label className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                          <input
                            type="radio"
                            name="client"
                            value={String(client.id)}
                            checked={clientId === String(client.id)}
                            onChange={() => setClientId(String(client.id))}
                          />
                          <span className="font-medium text-zinc-900 dark:text-zinc-50">{client.name}</span>
                          {client.phone ? <span className="text-zinc-500" dir="ltr">{client.phone}</span> : null}
                        </label>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ) : (
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="flex flex-col gap-1">
                  <label htmlFor="new-client-name" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    {t("wizard.step1.name")} <span className="text-red-500">*</span>
                  </label>
                  <input id="new-client-name" value={newClientName} onChange={(e) => setNewClientName(e.target.value)} className={INPUT} autoFocus />
                </div>
                <div className="flex flex-col gap-1">
                  <label htmlFor="new-client-phone" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    {t("wizard.step1.phone")}
                  </label>
                  <input
                    id="new-client-phone"
                    type="tel"
                    dir="ltr"
                    value={newClientPhone}
                    onChange={(e) => setNewClientPhone(e.target.value)}
                    className={INPUT}
                  />
                  <p className="text-xs text-zinc-500">{t("wizard.step1.phoneHint")}</p>
                </div>
              </div>
            )}
          </fieldset>
        ) : null}

        {step === 2 ? (
          <fieldset className="flex flex-col gap-4">
            <legend className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("wizard.step2.title")}</legend>
            {clientProperties && clientProperties.length > 0 ? (
              <div className="flex flex-col gap-2" role="radiogroup" aria-label={t("wizard.step2.place")}>
                <span className="text-sm font-medium text-zinc-700 dark:text-zinc-300">{t("wizard.step2.place")}</span>
                {clientProperties.map((prop) => (
                  <label key={prop.id} className="flex cursor-pointer items-center gap-3 rounded-md border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800">
                    <input
                      type="radio"
                      name="place"
                      checked={placeChoice === String(prop.id)}
                      onChange={() => setPlaceChoice(String(prop.id))}
                    />
                    <span className="text-zinc-900 dark:text-zinc-50">
                      {[prop.type ? t(`property.type.${prop.type}` as TranslationKey) : null, prop.compound, prop.address]
                        .filter(Boolean)
                        .join(" · ") || t("wizard.step2.unnamedPlace")}
                      {prop.area_m2 ? <span className="text-zinc-500"> · {prop.area_m2} m²</span> : null}
                    </span>
                  </label>
                ))}
                <label className="flex cursor-pointer items-center gap-3 rounded-md border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800">
                  <input type="radio" name="place" checked={effectivePlace === NEW_PLACE} onChange={() => setPlaceChoice(NEW_PLACE)} />
                  <span className="text-zinc-900 dark:text-zinc-50">{t("wizard.step2.newPlace")}</span>
                </label>
              </div>
            ) : null}
            {effectivePlace === NEW_PLACE ? (
            <>
            <div className="flex flex-col gap-2">
              <span className="text-sm font-medium text-zinc-700 dark:text-zinc-300">{t("wizard.step2.type")}</span>
              <div className="flex flex-wrap gap-2" role="radiogroup">
                {PROPERTY_TYPES.map((type) => (
                  <button
                    key={type}
                    type="button"
                    role="radio"
                    aria-checked={propertyType === type}
                    onClick={() => setPropertyType((cur) => (cur === type ? "" : type))}
                    className={`rounded-md border px-3 py-2 text-sm ${
                      propertyType === type
                        ? "border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-950"
                        : "border-zinc-300 text-zinc-700 hover:border-zinc-500 dark:border-zinc-700 dark:text-zinc-200"
                    }`}
                  >
                    {t(`property.type.${type}` as TranslationKey)}
                  </button>
                ))}
              </div>
            </div>
            {propertyType ? (
              <div className="flex max-w-xs flex-col gap-1">
                <label htmlFor="area" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                  {t("wizard.step2.area")}
                </label>
                <input id="area" type="number" min="1" inputMode="decimal" value={area} onChange={(e) => setArea(e.target.value)} className={INPUT} />
              </div>
            ) : null}
            </>
            ) : null}
            <div className="flex flex-col gap-1">
              <label htmlFor="project-name" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {t("wizard.step2.name")} <span className="text-red-500">*</span>
              </label>
              <input
                id="project-name"
                value={effectiveName}
                onChange={(e) => {
                  setNameTouched(true);
                  setProjectName(e.target.value);
                }}
                className={INPUT}
              />
              <p className="text-xs text-zinc-500">{t("wizard.step2.nameHint")}</p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-1">
                <label htmlFor="start-date" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                  {t("wizard.step2.startDate")}
                </label>
                <input id="start-date" type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} className={INPUT} />
              </div>
              <div className="flex flex-col gap-1">
                <label htmlFor="target-date" className="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                  {t("wizard.step2.targetEndDate")}
                </label>
                <input
                  id="target-date"
                  type="date"
                  min={startDate || undefined}
                  value={targetEndDate}
                  onChange={(e) => setTargetEndDate(e.target.value)}
                  className={INPUT}
                />
              </div>
            </div>
            <p className="text-xs text-zinc-500">{t("wizard.step2.datesHint")}</p>
          </fieldset>
        ) : null}

        {step === 3 ? (
          <fieldset className="flex flex-col gap-4">
            <legend className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">{t("wizard.step3.title")}</legend>
            <p className="text-sm text-zinc-600 dark:text-zinc-300">{t("wizard.step3.intro")}</p>
            {templates === null ? (
              <p className="text-sm text-zinc-500">{t("common.loading")}</p>
            ) : (
              <div className="flex flex-col gap-2" role="radiogroup">
                {fullFinishing.map((tpl) => (
                  <TemplateOption
                    key={tpl.id}
                    checked={templateChoice === String(tpl.id)}
                    onSelect={() => setTemplateChoice(String(tpl.id))}
                    title={localizedName(tpl, locale)}
                    badge={tpl.finishing_level ? finishingLevelLabel(t, tpl.finishing_level) : undefined}
                    recommended={tpl.finishing_level === "STANDARD" ? t("wizard.step3.suggested") : undefined}
                    description={localizedDescription(tpl, locale)}
                  />
                ))}
                {otherStarts.length > 0 ? (
                  <p className="mt-2 text-xs font-medium uppercase tracking-wide text-zinc-400">{t("wizard.step3.other")}</p>
                ) : null}
                {otherStarts.map((tpl) => (
                  <TemplateOption
                    key={tpl.id}
                    checked={templateChoice === String(tpl.id)}
                    onSelect={() => setTemplateChoice(String(tpl.id))}
                    title={localizedName(tpl, locale)}
                    description={localizedDescription(tpl, locale)}
                  />
                ))}
                <TemplateOption
                  checked={templateChoice === EMPTY}
                  onSelect={() => setTemplateChoice(EMPTY)}
                  title={t("wizard.step3.empty")}
                  description={t("wizard.step3.emptyHint")}
                />
              </div>
            )}
            <p className="text-xs text-zinc-500">{t("wizard.step3.later")}</p>
          </fieldset>
        ) : null}

        {error ? <ErrorBanner message={error} /> : null}
        {templateFailed && created.projectId ? (
          <div className="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            {t("wizard.templateFailed")}{" "}
            <Link href={`/projects/${created.projectId}/boq`} className="font-medium underline">
              {t("wizard.continueToProject")}
            </Link>
          </div>
        ) : null}

        <div className="mt-6 flex items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
          {step > 1 ? (
            <Button type="button" variant="secondary" onClick={() => setStep((s) => (s - 1) as Step)} disabled={submitting}>
              {t("wizard.back")}
            </Button>
          ) : (
            <span />
          )}
          {step < 3 ? (
            <Button
              type="button"
              onClick={() => setStep((s) => (s + 1) as Step)}
              disabled={step === 1 ? !step1Valid : !step2Valid}
            >
              {t("wizard.next")}
            </Button>
          ) : (
            <Button type="button" onClick={handleCreate} disabled={submitting || templates === null}>
              {submitting ? t("wizard.creating") : error ? t("wizard.retry") : t("wizard.create")}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

function TemplateOption({
  checked,
  onSelect,
  title,
  badge,
  recommended,
  description,
}: {
  checked: boolean;
  onSelect: () => void;
  title: string;
  badge?: string;
  recommended?: string;
  description?: string;
}) {
  return (
    <label
      className={`flex cursor-pointer gap-3 rounded-md border p-3 ${
        checked ? "border-zinc-900 bg-zinc-50 dark:border-zinc-100 dark:bg-zinc-800/50" : "border-zinc-200 hover:border-zinc-400 dark:border-zinc-800"
      }`}
    >
      <input type="radio" name="template" checked={checked} onChange={onSelect} className="mt-1" />
      <span className="min-w-0">
        <span className="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-900 dark:text-zinc-50">
          {title}
          {badge ? <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{badge}</span> : null}
          {recommended ? (
            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">{recommended}</span>
          ) : null}
        </span>
        {description ? <span className="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">{description}</span> : null}
      </span>
    </label>
  );
}
