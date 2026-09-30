"use client";

/**
 * Approve action form (S11): name + 6-digit OTP + optional comment. Distinguishes the three
 * error shapes the backend can return (see backend/app/Http/Controllers/Api/
 * PublicProposalController.php::approve() and docs/PROJECT_CONTEXT.md Sprint 4):
 *   - 422 OTP_INVALID   -> show message + attempts_remaining, let them retry.
 *   - 422 OTP_LOCKED     -> show a terminal lockout message, hide the form (no more attempts).
 *   - 422 OTP_EXPIRED    -> show a terminal message (no "resend OTP" endpoint exists this sprint).
 *   - 409 PROPOSAL_ALREADY_APPROVED -> bubble up via onAlreadyApproved so the parent screen can
 *     switch straight to the resolved/approved view instead of treating this as a form error.
 */

import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { approvePublicProposal } from "@/lib/api/resources/publicProposals";
import { PublicApiError } from "@/lib/api/publicClient";
import type { PublicApproveResult } from "@/lib/api/publicTypes";

const INPUT_CLASSES =
  "w-full rounded-md border border-zinc-300 px-3 py-2.5 text-base focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950";

interface ApproveFormProps {
  token: string;
  onApproved: (result: PublicApproveResult) => void;
  onAlreadyApproved: () => void;
  onCancel: () => void;
}

export function ApproveForm({ token, onApproved, onAlreadyApproved, onCancel }: ApproveFormProps) {
  const [name, setName] = useState("");
  const [otp, setOtp] = useState("");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [attemptsRemaining, setAttemptsRemaining] = useState<number | null>(null);
  const [locked, setLocked] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setAttemptsRemaining(null);
    setSubmitting(true);
    try {
      const result = await approvePublicProposal(token, {
        name: name.trim(),
        otp: otp.trim(),
        comment: comment.trim() ? comment.trim() : undefined,
      });
      onApproved(result);
    } catch (err) {
      if (err instanceof PublicApiError) {
        if (err.code === "PROPOSAL_ALREADY_APPROVED") {
          onAlreadyApproved();
          return;
        }
        if (err.code === "OTP_LOCKED") {
          setLocked(true);
          setError(err.message);
          return;
        }
        if (err.code === "OTP_EXPIRED") {
          setLocked(true);
          setError(
            "This verification code has expired. Please contact your design team for a new link.",
          );
          return;
        }
        if (err.code === "OTP_INVALID") {
          setError(err.message);
          const remaining = err.details.attempts_remaining;
          if (typeof remaining === "number") setAttemptsRemaining(remaining);
          return;
        }
        setError(err.message);
        return;
      }
      setError("Something went wrong. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  if (locked) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
        <p className="font-medium">This proposal can no longer be approved from this link.</p>
        <p className="mt-1">{error}</p>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
          Your name
        </label>
        <input
          className={INPUT_CLASSES}
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          maxLength={255}
          autoComplete="name"
          placeholder="Full name"
        />
      </div>

      <div>
        <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
          Verification code
        </label>
        <input
          className={`${INPUT_CLASSES} text-center text-2xl tracking-[0.5em]`}
          value={otp}
          onChange={(e) => setOtp(e.target.value.replace(/\D/g, "").slice(0, 6))}
          required
          inputMode="numeric"
          pattern="[0-9]{6}"
          maxLength={6}
          placeholder="000000"
          autoComplete="one-time-code"
        />
        <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
          Enter the 6-digit code your design team shared with you.
        </p>
      </div>

      <div>
        <label className="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
          Comment <span className="text-zinc-400">(optional)</span>
        </label>
        <textarea
          className={`${INPUT_CLASSES} resize-y`}
          value={comment}
          onChange={(e) => setComment(e.target.value)}
          rows={3}
          maxLength={2000}
          placeholder="Anything you'd like to add"
        />
      </div>

      {error ? (
        <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
          <p>{error}</p>
          {attemptsRemaining !== null ? (
            <p className="mt-0.5 font-medium">
              {attemptsRemaining} attempt{attemptsRemaining === 1 ? "" : "s"} remaining.
            </p>
          ) : null}
        </div>
      ) : null}

      <div className="flex gap-3">
        <Button type="submit" disabled={submitting || otp.length !== 6 || !name.trim()} className="flex-1">
          {submitting ? "Approving…" : "Confirm Approval"}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
