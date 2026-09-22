"use client";

/**
 * Request Changes action form (S11): name + required comment, no OTP — lower stakes than
 * approval per docs/PROJECT_CONTEXT.md ("a comment, not a binding commercial action"). Backend
 * validation requires `comment` (RequestChangesPublicProposalRequest), unlike approve()'s
 * optional comment.
 */

import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { requestPublicProposalChanges } from "@/lib/api/resources/publicProposals";
import { PublicApiError } from "@/lib/api/publicClient";

const INPUT_CLASSES =
  "w-full rounded-md border border-zinc-300 px-3 py-2.5 text-base focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950";

interface RequestChangesFormProps {
  token: string;
  onRequested: () => void;
  onAlreadyApproved: () => void;
  onCancel: () => void;
}

export function RequestChangesForm({
  token,
  onRequested,
  onAlreadyApproved,
  onCancel,
}: RequestChangesFormProps) {
  const [name, setName] = useState("");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await requestPublicProposalChanges(token, { name: name.trim(), comment: comment.trim() });
      onRequested();
    } catch (err) {
      if (err instanceof PublicApiError) {
        if (err.code === "PROPOSAL_ALREADY_APPROVED") {
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
          What would you like changed?
        </label>
        <textarea
          className={`${INPUT_CLASSES} resize-y`}
          value={comment}
          onChange={(e) => setComment(e.target.value)}
          required
          rows={4}
          maxLength={2000}
          placeholder="Let us know what you'd like adjusted"
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
          {submitting ? "Sending…" : "Send Feedback"}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
