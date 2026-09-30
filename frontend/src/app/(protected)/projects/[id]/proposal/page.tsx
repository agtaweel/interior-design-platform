"use client";

/**
 * S09 — Proposal Editor + S10 — Proposal Version History (PRD §4, PROJECT_CONTEXT.md Sprint 4
 * UX). Rendered together as one screen within the project's "Proposal" tab: a left-hand version
 * list (S10) drives which version's detail is shown on the right (S09's editor when that version
 * is a draft, or a read-only frozen view otherwise — same rendering path for both, per the
 * immutability rule in PROJECT_CONTEXT.md's Sprint 4 section).
 *
 * Version selection: defaults to the newest version (index 0 — verified live that
 * GET /projects/{id}/proposals returns versions newest-`version_no`-first). Clicking any row in
 * the history list re-fetches that version's full detail via GET /proposals/{id}.
 *
 * "One draft at a time" is a UI convention, not a backend rule: verified live that
 * POST /projects/{id}/proposals happily creates a second draft even while one already exists.
 * This screen enforces the sane product behavior anyway by disabling "Create New Version"
 * whenever any version in the list is already a draft, with a hint pointing at it, rather than
 * relying on a server-side guard that doesn't exist.
 *
 * content_json fields: the backend stores this as a free-form JSON blob (no fixed columns). This
 * screen fixes on exactly the six keys in `ProposalContent` (types.ts) and never varies them —
 * see that type's docblock for why consistency here matters.
 */

import { useEffect, useState, type ReactNode } from "react";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api/client";
import { createProposal, getProjectProposals, getProposal, sendProposal, updateProposal } from "@/lib/api/resources/proposals";
import type {
  ProposalContent,
  ProposalSendResult,
  ProposalStatus,
  ProposalVersion,
  ProposalVersionSummary,
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

const CONTENT_FIELDS: Array<{ key: keyof ProposalContent; labelKey: TranslationKey; rows: number }> = [
  { key: "cover", labelKey: "proposal.editor.fields.cover", rows: 3 },
  { key: "scope", labelKey: "proposal.editor.fields.scope", rows: 4 },
  { key: "exclusions", labelKey: "proposal.editor.fields.exclusions", rows: 3 },
  { key: "timeline", labelKey: "proposal.editor.fields.timeline", rows: 2 },
  { key: "terms", labelKey: "proposal.editor.fields.terms", rows: 3 },
  { key: "payment_plan", labelKey: "proposal.editor.fields.paymentPlan", rows: 2 },
];

const STATUS_TONE: Record<ProposalStatus, "neutral" | "blue" | "green" | "amber"> = {
  draft: "neutral",
  sent: "blue",
  approved: "green",
  changes_requested: "amber",
};

const TEXTAREA_CLASSES =
  "w-full resize-y rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

/** Form-friendly shape: every field guaranteed a plain string (never null/undefined), unlike
 *  `ProposalContent` itself which allows null/missing fields straight from the server. */
type ProposalFormContent = Record<keyof ProposalContent, string>;

function emptyContent(): ProposalFormContent {
  return { cover: "", scope: "", exclusions: "", timeline: "", terms: "", payment_plan: "" };
}

/** Normalizes a server `content_json` (which may be `null`, or missing any of our six fields)
 *  into a fully-populated form-friendly shape — see ProposalContent's docblock in types.ts. */
function normalizeContent(content: ProposalContent | null | undefined): ProposalFormContent {
  const base = emptyContent();
  if (!content) return base;
  for (const field of CONTENT_FIELDS) {
    base[field.key] = content[field.key] ?? "";
  }
  return base;
}

function contentEquals(a: ProposalFormContent, b: ProposalFormContent): boolean {
  return CONTENT_FIELDS.every((f) => a[f.key] === b[f.key]);
}

export default function ProposalPage() {
  const params = useParams<{ id: string }>();
  const projectId = params.id;
  const { t, locale } = useLocale();

  const [versions, setVersions] = useState<ProposalVersionSummary[]>([]);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<ProposalVersion | null>(null);
  const [listLoading, setListLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [creatingVersion, setCreatingVersion] = useState(false);

  // Per-version "just sent in this session" results — GET /proposals/{id} never returns
  // public_url/otp_code (the OTP is hashed server-side), so this is the only place these values
  // ever live once returned from POST /proposals/{id}/send. Keyed by proposal id so switching
  // versions and back doesn't lose it within the same page session.
  const [sendResults, setSendResults] = useState<Record<string, ProposalSendResult>>({});

  async function loadVersions(selectAfterId?: string) {
    setListLoading(true);
    setLoadError(null);
    try {
      const list = await getProjectProposals(projectId);
      setVersions(list);
      const fallback = list[0] ? String(list[0].id) : null;
      setSelectedId(selectAfterId ?? fallback);
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.message : t("common.unknownError"));
    } finally {
      setListLoading(false);
    }
  }

  // Intentional fetch-on-mount, matching every other screen in this app (see boq/page.tsx).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadVersions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  useEffect(() => {
    if (!selectedId) {
      setDetail(null);
      return;
    }
    let cancelled = false;
    setDetailLoading(true);
    setLoadError(null);
    getProposal(selectedId)
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
  }, [selectedId]);

  const hasDraft = versions.some((v) => v.status === "draft");

  async function handleCreateVersion() {
    setCreatingVersion(true);
    setActionError(null);
    try {
      const created = await createProposal(projectId, {});
      await loadVersions(String(created.id));
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : t("proposal.newVersion.failed"));
    } finally {
      setCreatingVersion(false);
    }
  }

  function handleDetailUpdated(updated: ProposalVersion) {
    setDetail(updated);
    setVersions((prev) =>
      prev.map((v) =>
        String(v.id) === String(updated.id)
          ? { ...v, status: updated.status, grand_total: updated.grand_total, sent_at: updated.sent_at, approved_at: updated.approved_at }
          : v,
      ),
    );
  }

  function handleSent(updated: ProposalVersion, result: ProposalSendResult) {
    handleDetailUpdated(updated);
    setSendResults((prev) => ({ ...prev, [String(updated.id)]: result }));
  }

  if (listLoading) return <LoadingScreen label={t("common.loading")} />;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("proposal.title")}</h1>
          <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("proposal.subtitle")}</p>
        </div>
        <div className="flex flex-col items-end gap-1">
          <Button onClick={handleCreateVersion} disabled={hasDraft || creatingVersion}>
            {creatingVersion ? t("proposal.newVersion.creating") : t("proposal.newVersion.cta")}
          </Button>
          {hasDraft ? (
            <p className="max-w-xs text-right text-xs text-amber-600 dark:text-amber-400">
              {t("proposal.newVersion.blockedHint")}
            </p>
          ) : null}
        </div>
      </div>

      {loadError ? <ErrorBanner message={loadError} onRetry={() => loadVersions(selectedId ?? undefined)} retryLabel={t("common.retry")} /> : null}
      {actionError ? <ErrorBanner message={actionError} /> : null}

      {versions.length === 0 ? (
        <Card>
          <CardBody>
            <EmptyState message={`${t("proposal.empty.title")} ${t("proposal.empty.action")}`} />
          </CardBody>
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[280px_1fr]">
          <VersionHistoryList
            versions={versions}
            selectedId={selectedId}
            onSelect={setSelectedId}
            t={t}
            locale={locale}
          />

          <div>
            {detailLoading ? (
              <LoadingScreen label={t("common.loading")} />
            ) : detail ? (
              <ProposalVersionPanel
                key={detail.id}
                detail={detail}
                hasOtherDraft={hasDraft && detail.status !== "draft"}
                sendResult={sendResults[String(detail.id)] ?? null}
                onUpdated={handleDetailUpdated}
                onSent={handleSent}
                onCreateVersion={handleCreateVersion}
                creatingVersion={creatingVersion}
                t={t}
                locale={locale}
              />
            ) : (
              <EmptyState message={t("common.notFound")} />
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function VersionHistoryList({
  versions,
  selectedId,
  onSelect,
  t,
  locale,
}: {
  versions: ProposalVersionSummary[];
  selectedId: string | null;
  onSelect: (id: string) => void;
  t: T;
  locale: Locale;
}) {
  const { formatMoney } = useMoneyFormatter();

  return (
    <Card className="h-fit">
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("proposal.list.title")}</h2>
      </CardHeader>
      <CardBody className="flex flex-col gap-1 p-2">
        {versions.map((v) => {
          const active = String(v.id) === selectedId;
          return (
            <button
              key={v.id}
              type="button"
              onClick={() => onSelect(String(v.id))}
              className={`flex flex-col gap-1 rounded-md px-3 py-2 text-left transition-colors ${
                active
                  ? "bg-amber-600 text-white dark:bg-amber-500 dark:text-zinc-950"
                  : "text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
              }`}
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium">
                  {t("proposal.list.version")} {v.version_no}
                </span>
                <Badge tone={STATUS_TONE[v.status]}>{t(`proposal.status.${v.status}`)}</Badge>
              </div>
              <span className={`text-xs ${active ? "text-white/80 dark:text-zinc-900/70" : "text-zinc-400"}`}>
                {t("proposal.list.grandTotal")}: {formatMoney(v.grand_total)}
              </span>
              <span className={`text-xs ${active ? "text-white/70 dark:text-zinc-900/60" : "text-zinc-400"}`}>
                {v.sent_at ? formatDateTime(v.sent_at, locale) : t("proposal.detail.notSentYet")}
              </span>
            </button>
          );
        })}
      </CardBody>
    </Card>
  );
}

interface ProposalVersionPanelProps {
  detail: ProposalVersion;
  /** True when this version isn't a draft but a *different* version already is — used to keep
   *  "Create New Version" from this panel consistent with the header button's guard. */
  hasOtherDraft: boolean;
  sendResult: ProposalSendResult | null;
  onUpdated: (updated: ProposalVersion) => void;
  onSent: (updated: ProposalVersion, result: ProposalSendResult) => void;
  onCreateVersion: () => void;
  creatingVersion: boolean;
  t: T;
  locale: Locale;
}

function ProposalVersionPanel({
  detail,
  hasOtherDraft,
  sendResult,
  onUpdated,
  onSent,
  onCreateVersion,
  creatingVersion,
  t,
  locale,
}: ProposalVersionPanelProps) {
  const { formatMoney } = useMoneyFormatter();
  const isDraft = detail.status === "draft";

  const savedContent = normalizeContent(detail.content_json);
  const [formContent, setFormContent] = useState<ProposalFormContent>(savedContent);
  const [saving, setSaving] = useState(false);
  const [sending, setSending] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [sendError, setSendError] = useState<string | null>(null);

  // Reset local form state whenever the underlying detail object changes (new version selected,
  // or a save/send round-trip replaced `detail`) — the panel is remounted via `key={detail.id}`
  // in the parent for version switches, but a save-in-place update needs this too.
  useEffect(() => {
    setFormContent(normalizeContent(detail.content_json));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [detail]);

  const dirty = !contentEquals(formContent, savedContent);

  async function handleSave() {
    setSaving(true);
    setSaveError(null);
    try {
      const updated = await updateProposal(detail.id, { content_json: formContent });
      onUpdated(updated);
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : t("proposal.editor.save.failed"));
    } finally {
      setSaving(false);
    }
  }

  async function handleSend() {
    setSending(true);
    setSendError(null);
    try {
      const result = await sendProposal(detail.id);
      const updated = await getProposal(detail.id);
      onSent(updated, result);
    } catch (err) {
      setSendError(err instanceof ApiError ? err.message : t("proposal.send.failed"));
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <h2 className="text-lg font-semibold text-zinc-900 dark:text-zinc-50">
              {t("proposal.list.version")} {detail.version_no}
            </h2>
            <Badge tone={STATUS_TONE[detail.status]}>{t(`proposal.status.${detail.status}`)}</Badge>
          </div>
          {!isDraft ? (
            <div className="flex flex-col items-end gap-1">
              <Button onClick={onCreateVersion} disabled={hasOtherDraft || creatingVersion}>
                {creatingVersion ? t("proposal.newVersion.creating") : t("proposal.newVersion.cta")}
              </Button>
              {hasOtherDraft ? (
                <p className="max-w-xs text-right text-xs text-amber-600 dark:text-amber-400">
                  {t("proposal.newVersion.blockedHint")}
                </p>
              ) : null}
            </div>
          ) : null}
        </CardHeader>
        <CardBody className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
          <Field label={t("proposal.detail.createdBy")} value={detail.created_by?.name ?? t("common.na")} />
          <Field
            label={t("proposal.detail.sentAt")}
            value={detail.sent_at ? formatDateTime(detail.sent_at, locale) : t("proposal.detail.notSentYet")}
          />
          <Field
            label={t("proposal.detail.approvedAt")}
            value={detail.approved_at ? formatDateTime(detail.approved_at, locale) : t("proposal.detail.notApprovedYet")}
          />
          <Field label={t("proposal.preview.totals.grandTotal")} value={formatMoney(detail.grand_total)} />
        </CardBody>
      </Card>

      {detail.status === "sent" ? (
        <SentShareCard result={sendResult} t={t} />
      ) : null}

      <Card>
        <CardHeader>
          <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
            {isDraft ? t("proposal.editor.title") : t("proposal.editor.readonlyTitle")}
          </h2>
          {!isDraft ? (
            <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">{t("proposal.editor.readonlyNote")}</p>
          ) : null}
        </CardHeader>
        <CardBody className="flex flex-col gap-4">
          {CONTENT_FIELDS.map((field) =>
            isDraft ? (
              <FormField key={field.key} label={t(field.labelKey)} htmlFor={`content-${field.key}`}>
                <textarea
                  id={`content-${field.key}`}
                  rows={field.rows}
                  value={formContent[field.key]}
                  onChange={(e) => setFormContent((prev) => ({ ...prev, [field.key]: e.target.value }))}
                  className={TEXTAREA_CLASSES}
                />
              </FormField>
            ) : (
              <div key={field.key}>
                <p className="text-xs font-medium uppercase tracking-wide text-zinc-400">{t(field.labelKey)}</p>
                <p className="mt-1 whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-200">
                  {savedContent[field.key] || t("proposal.editor.fields.empty")}
                </p>
              </div>
            ),
          )}

          {isDraft ? (
            <div className="flex flex-col gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-800">
              {saveError ? <ErrorBanner message={saveError} /> : null}
              {sendError ? <ErrorBanner message={sendError} /> : null}
              <div className="flex flex-wrap items-center gap-3">
                <Button variant="secondary" onClick={handleSave} disabled={!dirty || saving}>
                  {saving ? t("proposal.editor.save.saving") : t("proposal.editor.save.cta")}
                </Button>
                <Button onClick={handleSend} disabled={dirty || sending}>
                  {sending ? t("proposal.send.sending") : t("proposal.send.cta")}
                </Button>
                {dirty ? (
                  <span className="text-xs text-amber-600 dark:text-amber-400">{t("proposal.editor.unsavedHint")}</span>
                ) : null}
              </div>
            </div>
          ) : null}
        </CardBody>
      </Card>

      <ProposalPreview detail={detail} t={t} locale={locale} />
    </div>
  );
}

function SentShareCard({ result, t }: { result: ProposalSendResult | null; t: T }) {
  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
          {result ? t("proposal.send.resultTitle") : t("proposal.reshare.title")}
        </h2>
      </CardHeader>
      <CardBody className="flex flex-col gap-3">
        {result ? (
          <>
            <p className="text-sm text-zinc-600 dark:text-zinc-300">{t("proposal.send.resultNote")}</p>
            <CopyField label={t("proposal.send.linkLabel")} value={result.public_url} t={t} />
            <CopyField label={t("proposal.send.otpLabel")} value={result.otp_code} t={t} />
            <p className="text-xs text-zinc-400">{t("proposal.send.otpOnceNote")}</p>
          </>
        ) : (
          <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("proposal.reshare.onceOnlyNote")}</p>
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
          {copied ? t("proposal.send.copied") : t("proposal.send.copy")}
        </Button>
      </div>
    </div>
  );
}

/** Client-shaped preview: BOQ line items + totals only — no cost/margin fields, matching what
 *  the public portal (S11) will eventually show. See file docblock. */
function ProposalPreview({ detail, t, locale }: { detail: ProposalVersion; t: T; locale: Locale }) {
  const { formatMoney } = useMoneyFormatter();

  return (
    <Card>
      <CardHeader>
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">{t("proposal.preview.title")}</h2>
        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{t("proposal.preview.subtitle")}</p>
      </CardHeader>
      <CardBody className="overflow-x-auto p-0">
        {detail.items.length === 0 ? (
          <EmptyState message={t("proposal.preview.items.empty")} />
        ) : (
          <table className="w-full min-w-[640px] border-collapse text-sm">
            <thead>
              <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-800">
                <th className="px-3 py-2">{t("proposal.preview.items.columns.description")}</th>
                <th className="px-3 py-2 text-right">{t("proposal.preview.items.columns.quantity")}</th>
                <th className="px-3 py-2">{t("proposal.preview.items.columns.unit")}</th>
                <th className="px-3 py-2 text-right">{t("proposal.preview.items.columns.unitPrice")}</th>
                <th className="px-3 py-2 text-right">{t("proposal.preview.items.columns.lineTotal")}</th>
              </tr>
            </thead>
            <tbody>
              {detail.items.map((item) => (
                <tr key={item.id} className="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                  <td className="px-3 py-2 align-top text-zinc-900 dark:text-zinc-100">{item.description}</td>
                  <td className="px-3 py-2 text-right align-top text-zinc-600 dark:text-zinc-300">{item.quantity}</td>
                  <td className="px-3 py-2 align-top text-zinc-600 dark:text-zinc-300">{item.unit}</td>
                  <td className="px-3 py-2 text-right align-top text-zinc-600 dark:text-zinc-300">
                    {formatMoney(item.unit_price)}
                  </td>
                  <td className="px-3 py-2 text-right align-top font-medium text-zinc-900 dark:text-zinc-100">
                    {formatMoney(item.line_total)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </CardBody>
      <CardBody className="flex flex-col gap-1 border-t border-zinc-200 text-sm dark:border-zinc-800">
        <div className="flex items-baseline justify-between">
          <span className="text-zinc-600 dark:text-zinc-300">{t("proposal.preview.totals.subtotal")}</span>
          <span>{formatMoney(detail.subtotal)}</span>
        </div>
        <div className="flex items-baseline justify-between">
          <span className="text-zinc-600 dark:text-zinc-300">{t("proposal.preview.totals.markupTotal")}</span>
          <span>{formatMoney(detail.markup_total)}</span>
        </div>
        <div className="flex items-baseline justify-between">
          <span className="text-zinc-600 dark:text-zinc-300">{t("proposal.preview.totals.feesTotal")}</span>
          <span>{formatMoney(detail.fees_total)}</span>
        </div>
        <div className="flex items-baseline justify-between">
          <span className="text-zinc-600 dark:text-zinc-300">{t("proposal.preview.totals.discountTotal")}</span>
          <span>− {formatMoney(detail.discount_total)}</span>
        </div>
        <div className="mt-1 flex items-baseline justify-between border-t border-zinc-200 pt-2 dark:border-zinc-800">
          <span className="text-base font-semibold text-zinc-900 dark:text-zinc-50">
            {t("proposal.preview.totals.grandTotal")}
          </span>
          <span className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">
            {formatMoney(detail.grand_total)}
          </span>
        </div>
      </CardBody>
    </Card>
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
