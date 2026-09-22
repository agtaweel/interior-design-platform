"use client";

/**
 * Reject action form (S14): name + required comment, no OTP — mirrors
 * `/p/proposals/[token]/RequestChangesForm.tsx`'s "lower stakes than a binding commercial
 * approval" reasoning (PROJECT_CONTEXT.md Sprint 7: reject mirrors proposals' request-changes).
 * Backend validation requires `comment` (RejectPublicChangeOrderRequest).
 */

import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { rejectPublicChangeOrder } from "@/lib/api/resources/publicChangeOrders";
import { PublicApiError } from "@/lib/api/publicClient";

const INPUT_CLASSES =
  "w-full rounded-md border border-zinc-300 px-3 py-2.5 text-base focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";

interface RejectFormProps {
  token: string;
  onRejected: () => void;
  onAlreadyApproved: () => void;
  onCancel: () => void;
}

export function RejectForm({ token, onRejected, onAlreadyApproved, onCancel }: RejectFormProps) {
  const [name, setName] = useState("");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await rejectPublicChangeOrder(token, { name: name.trim(), comment: comment.trim() });
      onRejected();
    } catch (err) {
      if (err instanceof PublicApiError) {
        if (err.code === "CHANGE_ORDER_ALREADY_APPROVED") {
          onAlreadyApproved();
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
          Why are you rejecting this change?
        </label>
        <textarea
          className={`${INPUT_CLASSES} resize-y`}
          value={comment}
          onChange={(e) => setComment(e.target.value)}
          required
          rows={4}
          maxLength={2000}
          placeholder="Let us know your reason"
        />
      </div>

      {error ? (
        <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
          {error}
        </div>
      ) : null}

      <div className="flex gap-3">
        <Button
          type="submit"
          disabled={submitting || !name.trim() || !comment.trim()}
          className="flex-1"
        >
          {submitting ? "Sending…" : "Confirm Rejection"}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
