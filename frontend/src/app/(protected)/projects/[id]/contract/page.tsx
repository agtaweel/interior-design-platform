"use client";

/**
 * S12 — Contract (PROJECT_CONTEXT.md Sprint 5). Contract creation IS the signing act (the
 * client already OTP-approved the proposal in Sprint 4's public portal — there is no second
 * client-facing signing ceremony here), so this screen has three states depending on what
 * exists for this project:
 *   1. No contract, no approved proposal version -> empty state pointing at the Proposal tab.
 *   2. No contract, an approved proposal version exists -> "Convert to Contract" CTA.
 *   3. A contract exists -> metadata + parties + a locked reference back to the source proposal
 *      version + an editable start_date/end_date/terms_json form + a PDF download action.
 *
 * `GET /projects/{id}/contracts` (see lib/api/resources/contracts.ts) tells this screen up
 * front whether a contract already exists for the project, so state resolution on page load is
 * a single fetch — no client-side caching needed, and the page is correct on first load from
 * any browser/device. The from-proposal conversion's 409 CONTRACT_ALREADY_EXISTS response now
 * also carries `details.contract_id`, so even the race where a contract was created between
 * this page's initial load and the user clicking "Convert" resolves automatically by fetching
 * that id — the manual "load by ID" input only appears as a last-resort fallback if that id is
 * ever missing from the error details.
 */

import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { ApiError } from "@/lib/api/client";
import { getProjectProposals } from "@/lib/api/resources/proposals";
import {
  createContractFromProposal,
  downloadContractPdf,
  getContract,
  getProjectContracts,
  updateContract,
} from "@/lib/api/resources/contracts";
import type { Contract, ContractTerms, ProposalVersionSummary } from "@/lib/api/types";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useAuth } from "@/lib/auth/AuthContext";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";
import { type Locale } from "@/lib/format/currency";
import { useMoneyFormatter } from "@/lib/format/useMoneyFormatter";
import { formatDate, formatDateTime } from "@/lib/format/date";

type T = (key: TranslationKey) => string;

const TERMS_FIELDS: Array<{ key: keyof ContractTerms; labelKey: TranslationKey; rows: number }> = [
  { key: "terms", labelKey: "contract.form.fields.terms", rows: 3 },
  { key: "exclusions", labelKey: "contract.form.fields.exclusions", rows: 3 },
  { key: "timeline", labelKey: "contract.form.fields.timeline", rows: 2 },
  { key: "payment_plan", labelKey: "contract.form.fields.paymentPlan", rows: 2 },
  { key: "warranty_period", labelKey: "contract.form.fields.warrantyPeriod", rows: 2 },
  { key: "cancellation_policy", labelKey: "contract.form.fields.cancellationPolicy", rows: 2 },
];

const TEXTAREA_CLASSES =
  "w-full resize-y rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";
const INPUT_CLASSES =
  "w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";

/** Form-friendly shape: every field guaranteed a plain string, mirroring proposal/page.tsx's
 *  ProposalFormContent convention for the same "flexible JSON blob, fixed key subset" pattern. */
type TermsForm = Record<keyof ContractTerms, string>;

function emptyTerms(): TermsForm {
  return {
    terms: "",
    exclusions: "",
    timeline: "",
    payment_plan: "",
    warranty_period: "",
    cancellation_policy: "",
  };
}

function normalizeTerms(terms: ContractTerms | null | undefined): TermsForm {
  const base = emptyTerms();
  if (!terms) return base;
  for (const field of TERMS_FIELDS) {
    base[field.key] = terms[field.key] ?? "";
  }
  return base;
}

/** ISO datetime/date -> "YYYY-MM-DD" for <input type="date">; "" for null/missing. */
function toDateInputValue(value: string | null | undefined): string {
  if (!value) return "";
  return value.slice(0, 10);
}

function statusLabel(status: string, t: T): string {
  return status === "active" ? t("contract.status.active") : status;
}

export default function ContractPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t, locale } = useLocale();
  const { organizations, currentOrganizationId } = useAuth();

  const [phase, setPhase] = useState<"loading" | "empty" | "convert" | "alreadyExists" | "contract">(
    "loading",
  );
  const [candidateProposal, setCandidateProposal] = useState<ProposalVersionSummary | null>(null);
  const [contract, setContract] = useState<Contract | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  async function load() {
    setPhase("loading");
    setLoadError(null);

    try {
      const contracts = await getProjectContracts(projectId);
      if (contracts.length > 0) {
        setContract(contracts[0]);
        setPhase("contract");
        return;
      }

      const versions = await getProjectProposals(projectId);
      const approved = versions.find((v) => v.status === "approved") ?? null;
      setCandidateProposal(approved);
      setPhase(approved ? "convert" : "empty");
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
      setPhase("empty");
    }
  }

  // Intentional fetch-on-mount, matching every other screen in this app (see proposal/page.tsx).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  function handleConverted(created: Contract) {
    setContract(created);
    setPhase("contract");
  }

  function handleAlreadyExists() {
    setPhase("alreadyExists");
  }

  function handleManualLoaded(found: Contract) {
    setContract(found);
    setPhase("contract");
  }

  const organizationName =
    organizations.find((m) => String(m.organization.id) === currentOrganizationId)?.organization
      .name ?? null;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("contract.title")}</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("contract.subtitle")}</p>
      </div>

      {loadError ? (
        <ErrorBanner message={loadError} onRetry={load} retryLabel={t("common.retry")} />
      ) : null}

      {phase === "loading" ? <LoadingScreen label={t("common.loading")} /> : null}

      {phase === "empty" ? <EmptyStateCard projectId={projectId} t={t} /> : null}

      {phase === "convert" && candidateProposal ? (
        <ConvertCard
          projectId={projectId}
          proposal={candidateProposal}
          onConverted={handleConverted}
          onAlreadyExists={handleAlreadyExists}
          t={t}
          locale={locale}
        />
      ) : null}

      {phase === "alreadyExists" ? (
        <AlreadyExistsCard onLoaded={handleManualLoaded} t={t} />
      ) : null}

      {phase === "contract" && contract ? (
        <ContractDetail
          projectId={projectId}
          contract={contract}
          organizationName={organizationName}
          onUpdated={setContract}
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
        <EmptyState message={`${t("contract.empty.title")} ${t("contract.empty.body")}`} />
        <Link
          href={`/projects/${projectId}/proposal`}
          className="text-sm font-medium text-zinc-900 underline underline-offset-2 dark:text-zinc-50"
        >
          {t("contract.empty.viewProposal")}
        </Link>
      </CardBody>
    </Card>
  );
}

function ConvertCard({
  projectId,
  proposal,
  onConverted,
  onAlreadyExists,
  t,
  locale,
}: {
  projectId: string;
  proposal: ProposalVersionSummary;
  onConverted: (created: Contract) => void;
  onAlreadyExists: () => void;
  t: T;
  locale: Locale;
}) {
  const { formatMoney } = useMoneyFormatter();
  const [converting, setConverting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleConvert() {
    setConverting(true);
    setError(null);
    try {
      const created = await createContractFromProposal(projectId, proposal.id);
      onConverted(created);
    } catch (err) {
      if (err instanceof ApiError && err.code === "CONTRACT_ALREADY_EXISTS") {
        // The 409 tells us the contract's id directly now — fetch and show it automatically
        // rather than making the user type anything. Only fall back to the manual "load by ID"
        // input in the (should-never-happen) case where the id is missing from the error
        // details.
        const contractId = err.details.contract_id;
        if (typeof contractId === "string" || typeof contractId === "number") {
          try {
            const found = await getContract(contractId);
            onConverted(found);
            return;
          } catch {
            // Fall through to the manual fallback below — the id we were given didn't resolve.
          }
        }
        onAlreadyExists();
        return;
      }
      setError(err instanceof ApiError ? err.message : t("contract.convert.failed"));
    } finally {
      setConverting(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
          {t("contract.convert.title")}
        </h2>
      </CardHeader>
      <CardBody className="flex flex-col gap-4">
        <p className="text-sm text-zinc-600 dark:text-zinc-300">{t("contract.convert.body")}</p>
        <div className="flex flex-wrap items-center gap-4 text-sm">
          <Badge tone="green">{t("proposal.status.approved")}</Badge>
          <span className="text-zinc-500 dark:text-zinc-400">
            {t("proposal.list.version")} {proposal.version_no}
          </span>
          <span className="font-medium text-zinc-900 dark:text-zinc-100">
            {t("proposal.list.grandTotal")}: {formatMoney(proposal.grand_total)}
          </span>
        </div>
        {error ? <ErrorBanner message={error} /> : null}
        <div>
          <Button onClick={handleConvert} disabled={converting}>
            {converting ? t("contract.convert.converting") : t("contract.convert.cta")}
          </Button>
        </div>
      </CardBody>
    </Card>
  );
}

function AlreadyExistsCard({
  onLoaded,
  t,
}: {
  onLoaded: (found: Contract) => void;
  t: T;
}) {
  const [idInput, setIdInput] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const trimmed = idInput.trim();
    if (!trimmed) return;
    setLoading(true);
    setError(null);
    try {
      const found = await getContract(trimmed);
      onLoaded(found);
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        setError(t("contract.alreadyExists.notFound"));
      } else {
        setError(err instanceof ApiError ? err.message : t("common.unknownError"));
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
          {t("contract.alreadyExists.title")}
        </h2>
      </CardHeader>
      <CardBody className="flex flex-col gap-4">
        <p className="text-sm text-zinc-600 dark:text-zinc-300">{t("contract.alreadyExists.body")}</p>
        <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1">
            <label
              htmlFor="contract-id-input"
              className="text-xs font-medium text-zinc-500 dark:text-zinc-400"
            >
              {t("contract.alreadyExists.idLabel")}
            </label>
            <input
              id="contract-id-input"
              value={idInput}
              onChange={(e) => setIdInput(e.target.value)}
              placeholder={t("contract.alreadyExists.idPlaceholder")}
              className={`${INPUT_CLASSES} w-40`}
            />
          </div>
          <Button type="submit" variant="secondary" disabled={loading || !idInput.trim()}>
            {loading ? t("contract.alreadyExists.loading") : t("contract.alreadyExists.loadCta")}
          </Button>
        </form>
        {error ? <ErrorBanner message={error} /> : null}
      </CardBody>
    </Card>
  );
}

function ContractDetail({
  projectId,
  contract,
  organizationName,
  onUpdated,
  t,
  locale,
}: {
  projectId: string;
  contract: Contract;
  organizationName: string | null;
  onUpdated: (updated: Contract) => void;
  t: T;
  locale: Locale;
}) {
  const { formatMoney } = useMoneyFormatter();
  const savedTerms = normalizeTerms(contract.terms_json);
  const savedStartDate = toDateInputValue(contract.start_date);
  const savedEndDate = toDateInputValue(contract.end_date);

  const [formTerms, setFormTerms] = useState<TermsForm>(savedTerms);
  const [startDate, setStartDate] = useState(savedStartDate);
  const [endDate, setEndDate] = useState(savedEndDate);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  // Reset local form state whenever the underlying contract changes (a save round-trip replaced
  // it, or a fresh contract was just loaded) — same pattern as proposal/page.tsx's panel.
  useEffect(() => {
    setFormTerms(normalizeTerms(contract.terms_json));
    setStartDate(toDateInputValue(contract.start_date));
    setEndDate(toDateInputValue(contract.end_date));
  }, [contract]);

  const dirty =
    startDate !== savedStartDate ||
    endDate !== savedEndDate ||
    TERMS_FIELDS.some((field) => formTerms[field.key] !== savedTerms[field.key]);

  async function handleSave() {
    setSaving(true);
    setSaveError(null);
    try {
      const updated = await updateContract(contract.id, {
        start_date: startDate || null,
        end_date: endDate || null,
        terms_json: formTerms,
      });
      onUpdated(updated);
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : t("contract.form.save.failed"));
    } finally {
      setSaving(false);
    }
  }

  async function handleDownload() {
    setDownloading(true);
    setDownloadError(null);
    try {
      await downloadContractPdf(contract.id, contract.contract_no);
    } catch {
      setDownloadError(t("contract.detail.downloadFailed"));
    } finally {
      setDownloading(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <h2 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
              {contract.contract_no}
            </h2>
            <Badge tone={contract.status === "active" ? "green" : "neutral"}>
              {statusLabel(contract.status, t)}
            </Badge>
          </div>
          <Button variant="secondary" onClick={handleDownload} disabled={downloading}>
            {downloading ? t("contract.detail.downloading") : t("contract.detail.downloadPdf")}
          </Button>
        </CardHeader>
        <CardBody className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
          <Field
            label={t("contract.detail.value")}
            value={formatMoney(contract.contract_value)}
            note={t("contract.detail.valueLockedNote")}
          />
          <Field label={t("contract.detail.signedAt")} value={formatDateTime(contract.signed_at, locale)} />
          <Field label={t("contract.form.startDate")} value={formatDate(contract.start_date, locale)} />
          <Field label={t("contract.form.endDate")} value={formatDate(contract.end_date, locale)} />
          <Field label={t("contract.detail.client")} value={contract.client?.name ?? t("common.na")} />
          <Field label={t("contract.detail.organization")} value={organizationName ?? t("common.na")} />
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">
              {t("contract.detail.sourceProposal")}
            </p>
            <p className="mt-0.5 text-zinc-900 dark:text-zinc-100">
              {contract.proposal_version ? (
                <Link
                  href={`/projects/${projectId}/proposal`}
                  className="underline underline-offset-2"
                >
                  {t("proposal.list.version")} {contract.proposal_version.version_no}
                </Link>
              ) : (
                t("common.na")
              )}
            </p>
            <p className="mt-0.5 text-xs text-amber-600 dark:text-amber-400">
              {t("contract.detail.sourceProposalLockedNote")}
            </p>
          </div>
        </CardBody>
        {downloadError ? (
          <CardBody className="border-t border-zinc-200 pt-3 dark:border-zinc-800">
            <ErrorBanner message={downloadError} />
          </CardBody>
        ) : null}
      </Card>

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
            {t("contract.form.title")}
          </h2>
        </CardHeader>
        <CardBody className="flex flex-col gap-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label={t("contract.form.startDate")} htmlFor="contract-start-date">
              <input
                id="contract-start-date"
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className={INPUT_CLASSES}
              />
            </FormField>
            <FormField label={t("contract.form.endDate")} htmlFor="contract-end-date">
              <input
                id="contract-end-date"
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className={INPUT_CLASSES}
              />
            </FormField>
          </div>

          {TERMS_FIELDS.map((field) => (
            <FormField key={field.key} label={t(field.labelKey)} htmlFor={`contract-${field.key}`}>
              <textarea
                id={`contract-${field.key}`}
                rows={field.rows}
                value={formTerms[field.key]}
                onChange={(e) => setFormTerms((prev) => ({ ...prev, [field.key]: e.target.value }))}
                className={TEXTAREA_CLASSES}
              />
            </FormField>
          ))}

          <div className="flex flex-col gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-800">
            {saveError ? <ErrorBanner message={saveError} /> : null}
            <div className="flex flex-wrap items-center gap-3">
              <Button onClick={handleSave} disabled={!dirty || saving}>
                {saving ? t("contract.form.save.saving") : t("contract.form.save.cta")}
              </Button>
              {dirty ? (
                <span className="text-xs text-amber-600 dark:text-amber-400">
                  {t("contract.form.unsavedHint")}
                </span>
              ) : null}
            </div>
          </div>
        </CardBody>
      </Card>
    </div>
  );
}

function Field({ label, value, note }: { label: string; value: string; note?: string }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{label}</p>
      <p className="mt-0.5 text-zinc-900 dark:text-zinc-100">{value}</p>
      {note ? <p className="mt-0.5 text-xs text-amber-600 dark:text-amber-400">{note}</p> : null}
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
