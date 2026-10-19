"use client";

/**
 * The client's decision on a proposal or a change order — approve with the 6-digit code, or
 * request changes / reject with a comment — in Arabic or English. One component for every place a
 * client decides (docs/SIMPLIFICATION_REVIEW.md P3): inside the client portal, and on the older
 * per-document links (/p/proposals/…, /p/change-orders/…) that are still honoured.
 *
 * Callers pass `approve`/`decline` functions bound to their own endpoint; the backend applies the
 * same rules behind both (ProposalClientDecision / ChangeOrderClientDecision), so the error codes
 * handled here are the same everywhere:
 *   OTP_INVALID (retry, with attempts left) · OTP_LOCKED / OTP_EXPIRED (terminal) ·
 *   *_ALREADY_APPROVED (someone already decided — the parent reloads).
 * Messages come from the dictionary by code rather than the server's English text.
 */

import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { PublicApiError } from "@/lib/api/publicClient";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

const INPUT =
  "w-full rounded-md border border-zinc-300 px-3 py-2.5 text-base focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

export type DecisionKind = "proposal" | "changeOrder";
export type DecisionOutcome = "approved" | "declined" | "alreadyDecided";

const ALREADY_DECIDED = ["PROPOSAL_ALREADY_APPROVED", "CHANGE_ORDER_ALREADY_APPROVED", "PROPOSAL_NOT_AWAITING_DECISION", "CHANGE_ORDER_NOT_AWAITING_DECISION"];

export function DecisionPanel({
  kind,
  approve,
  decline,
  onDecided,
}: {
  kind: DecisionKind;
  approve: (input: { name: string; otp: string; comment?: string }) => Promise<unknown>;
  decline: (input: { name: string; comment: string }) => Promise<unknown>;
  onDecided: (outcome: DecisionOutcome) => void;
}) {
  const { t } = useLocale();
  const [view, setView] = useState<"choose" | "approve" | "decline">("choose");

  return (
    <section className="space-y-3 rounded-lg border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
      {view === "choose" ? (
        <>
          <p className="text-sm font-medium text-zinc-800 dark:text-zinc-200">{t(`decision.${kind}.awaiting`)}</p>
          <div className="flex flex-col gap-3 sm:flex-row">
            <Button className="flex-1" onClick={() => setView("approve")}>
              {t(`decision.${kind}.approve`)}
            </Button>
            <Button variant="secondary" className="flex-1" onClick={() => setView("decline")}>
              {t(`decision.${kind}.decline`)}
            </Button>
          </div>
        </>
      ) : null}
      {view === "approve" ? (
        <ApproveForm kind={kind} submit={approve} onDone={onDecided} onCancel={() => setView("choose")} />
      ) : null}
      {view === "decline" ? (
        <DeclineForm kind={kind} submit={decline} onDone={onDecided} onCancel={() => setView("choose")} />
      ) : null}
    </section>
  );
}

function ApproveForm({
  kind,
  submit,
  onDone,
  onCancel,
}: {
  kind: DecisionKind;
  submit: (input: { name: string; otp: string; comment?: string }) => Promise<unknown>;
  onDone: (outcome: DecisionOutcome) => void;
  onCancel: () => void;
}) {
  const { t, locale } = useLocale();
  const [name, setName] = useState("");
  const [otp, setOtp] = useState("");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [attemptsLeft, setAttemptsLeft] = useState<number | null>(null);
  const [terminal, setTerminal] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setAttemptsLeft(null);
    setSubmitting(true);
    try {
      await submit({ name: name.trim(), otp: otp.trim(), comment: comment.trim() || undefined });
      onDone("approved");
    } catch (err) {
      if (err instanceof PublicApiError && err.code && ALREADY_DECIDED.includes(err.code)) {
        onDone("alreadyDecided");
      } else if (err instanceof PublicApiError && err.code === "OTP_LOCKED") {
        setTerminal(t("decision.error.locked"));
      } else if (err instanceof PublicApiError && err.code === "OTP_EXPIRED") {
        setTerminal(t("decision.error.expired"));
      } else if (err instanceof PublicApiError && err.code === "OTP_INVALID") {
        setError(t("decision.error.invalid"));
        const remaining = err.details.attempts_remaining;
        if (typeof remaining === "number") setAttemptsLeft(remaining);
      } else {
        setError(t("decision.error.generic"));
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (terminal) {
    return (
      <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
        {terminal}
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <Field id="decision-name" label={t("decision.name")}>
        <input id="decision-name" className={INPUT} value={name} onChange={(e) => setName(e.target.value)} required maxLength={255} autoComplete="name" />
      </Field>
      <Field id="decision-otp" label={t("decision.code")} hint={t("decision.codeHint")}>
        <input
          id="decision-otp"
          dir="ltr"
          className={`${INPUT} text-center text-2xl tracking-[0.5em]`}
          value={otp}
          onChange={(e) => setOtp(e.target.value.replace(/\D/g, "").slice(0, 6))}
          required
          inputMode="numeric"
          pattern="[0-9]{6}"
          maxLength={6}
          placeholder="000000"
          autoComplete="one-time-code"
        />
      </Field>
      <Field id="decision-comment" label={t("decision.commentOptional")}>
        <textarea id="decision-comment" className={`${INPUT} resize-y`} value={comment} onChange={(e) => setComment(e.target.value)} rows={2} maxLength={2000} />
      </Field>
      {error ? (
        <div role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
          <p>{error}</p>
          {attemptsLeft !== null ? <p className="mt-0.5 font-medium">{t("decision.attemptsLeft").replace("{n}", new Intl.NumberFormat(locale === "ar" ? "ar-EG" : "en-EG").format(attemptsLeft))}</p> : null}
        </div>
      ) : null}
      <div className="flex gap-3">
        <Button type="submit" className="flex-1" disabled={submitting || otp.length !== 6 || !name.trim()}>
          {submitting ? t("decision.approving") : t(`decision.${kind}.confirmApprove`)}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("decision.cancel")}
        </Button>
      </div>
    </form>
  );
}

function DeclineForm({
  kind,
  submit,
  onDone,
  onCancel,
}: {
  kind: DecisionKind;
  submit: (input: { name: string; comment: string }) => Promise<unknown>;
  onDone: (outcome: DecisionOutcome) => void;
  onCancel: () => void;
}) {
  const { t } = useLocale();
  const [name, setName] = useState("");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await submit({ name: name.trim(), comment: comment.trim() });
      onDone("declined");
    } catch (err) {
      if (err instanceof PublicApiError && err.code && ALREADY_DECIDED.includes(err.code)) onDone("alreadyDecided");
      else setError(t("decision.error.generic"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <Field id="decline-name" label={t("decision.name")}>
        <input id="decline-name" className={INPUT} value={name} onChange={(e) => setName(e.target.value)} required maxLength={255} autoComplete="name" />
      </Field>
      <Field id="decline-comment" label={t(`decision.${kind}.declineComment`)}>
        <textarea id="decline-comment" className={`${INPUT} resize-y`} value={comment} onChange={(e) => setComment(e.target.value)} required rows={3} maxLength={2000} />
      </Field>
      {error ? (
        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
          {error}
        </p>
      ) : null}
      <div className="flex gap-3">
        <Button type="submit" className="flex-1" disabled={submitting || !name.trim() || !comment.trim()}>
          {submitting ? t("decision.sending") : t(`decision.${kind}.confirmDecline`)}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          {t("decision.cancel")}
        </Button>
      </div>
    </form>
  );
}

function Field({ id, label, hint, children }: { id: string; label: string; hint?: string; children: React.ReactNode }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
        {label}
      </label>
      {children}
      {hint ? <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{hint}</p> : null}
    </div>
  );
}

/** What the client sees once a decision exists. `justNow` switches to the thank-you wording. */
export function DecisionBanner({ kind, status, justNow }: { kind: DecisionKind; status: string; justNow?: boolean }) {
  const { t } = useLocale();
  const key = status === "approved" || status === "applied" ? "approved" : status === "changes_requested" || status === "rejected" ? "declined" : null;
  if (!key) return null;
  const tone =
    key === "approved"
      ? "border-green-200 bg-green-50 text-green-900 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300"
      : "border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300";
  return (
    <div role="status" className={`rounded-lg border p-4 ${tone}`}>
      <p className="text-base font-semibold">{t(`decision.${kind}.${key}${justNow ? "Now" : ""}.title` as TranslationKey)}</p>
      <p className="mt-1 text-sm">{t(`decision.${kind}.${key}.body` as TranslationKey)}</p>
    </div>
  );
}
