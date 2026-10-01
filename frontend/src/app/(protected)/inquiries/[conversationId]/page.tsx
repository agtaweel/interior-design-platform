"use client";

/**
 * BRD v4 "Client Marketplace" Phase B — a single inquiry thread, staff side. Mirrors the client
 * thread screen's shape (same 60s poll, optimistic send-then-reconcile), with messages aligned
 * by sender_type so staff replies and client messages are visually distinguishable the same way
 * as the client-facing screen.
 */

import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { createClientFromInquiry, getInquiry, postInquiryMessage } from "@/lib/api/resources/inquiries";
import type { InquiryDetail } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { Button } from "@/components/ui/Button";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { formatDateTime } from "@/lib/format/date";

const POLL_INTERVAL_MS = 60_000;

export default function InquiryThreadPage() {
  const { t, locale } = useLocale();
  const params = useParams<{ conversationId: string }>();
  const router = useRouter();
  const [inquiry, setInquiry] = useState<InquiryDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [body, setBody] = useState("");
  const [sending, setSending] = useState(false);
  const [creatingClient, setCreatingClient] = useState(false);
  const [handoffNotice, setHandoffNotice] = useState<string | null>(null);
  const bottomRef = useRef<HTMLDivElement>(null);

  const load = useCallback(async () => {
    try {
      setInquiry(await getInquiry(params.conversationId));
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inquiries.loadFailed"));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- t is stable enough for this purpose
  }, [params.conversationId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  useEffect(() => {
    const interval = setInterval(load, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [load]);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ block: "nearest" });
  }, [inquiry]);

  async function handleCreateClient() {
    setCreatingClient(true);
    setHandoffNotice(null);
    setError(null);
    try {
      const result = await createClientFromInquiry(params.conversationId);
      if (result.client) {
        router.push(`/projects?new_client_id=${result.client.id}`);
        return;
      }
      setHandoffNotice(t("inquiries.handoff.ambiguous"));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inquiries.handoff.failed"));
    } finally {
      setCreatingClient(false);
    }
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const text = body.trim();
    if (!text || !inquiry) return;
    setSending(true);
    try {
      const message = await postInquiryMessage(params.conversationId, text);
      setInquiry((prev) => (prev ? { ...prev, messages: [...prev.messages, message] } : prev));
      setBody("");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t("inquiries.reply.failed"));
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <Link href="/inquiries" className="text-sm text-zinc-500 hover:underline dark:text-zinc-400">
        ← {t("inquiries.back")}
      </Link>

      {error ? <ErrorBanner message={error} onRetry={load} retryLabel={t("common.retry")} /> : null}

      {inquiry === null && !error ? (
        <LoadingScreen label={t("common.loading")} />
      ) : inquiry ? (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-xl font-semibold text-zinc-900 dark:text-zinc-50">{inquiry.client.name}</h1>
              <p className="text-sm text-zinc-500 dark:text-zinc-400">{inquiry.client.email}</p>
            </div>
            <Button variant="secondary" onClick={handleCreateClient} disabled={creatingClient}>
              {creatingClient ? t("inquiries.handoff.creating") : t("inquiries.handoff.cta")}
            </Button>
          </div>

          {handoffNotice ? <ErrorBanner message={handoffNotice} /> : null}

          <div className="flex min-h-[50vh] flex-col gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            {inquiry.messages.length === 0 ? (
              <p className="text-sm text-zinc-500 dark:text-zinc-400">{t("inquiries.noMessages")}</p>
            ) : (
              inquiry.messages.map((m) => (
                <div
                  key={m.id}
                  className={`max-w-[75%] rounded-lg px-3 py-2 text-sm ${
                    m.sender_type === "org_member"
                      ? "ms-auto bg-amber-500 text-white dark:bg-amber-500 dark:text-zinc-950"
                      : "bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-50"
                  }`}
                >
                  <p className="whitespace-pre-line">{m.body}</p>
                  <p
                    className={`mt-1 text-[10px] ${
                      m.sender_type === "org_member" ? "text-amber-50/80" : "text-zinc-400"
                    }`}
                  >
                    {formatDateTime(m.created_at, locale)}
                  </p>
                </div>
              ))
            )}
            <div ref={bottomRef} />
          </div>

          <form onSubmit={handleSubmit} className="flex items-end gap-2">
            <textarea
              rows={2}
              value={body}
              onChange={(e) => setBody(e.target.value)}
              placeholder={t("inquiries.reply.placeholder")}
              className="flex-1 resize-none rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950"
            />
            <Button type="submit" disabled={sending || !body.trim()}>
              {sending ? t("inquiries.reply.submitting") : t("inquiries.reply.submit")}
            </Button>
          </form>
        </>
      ) : null}
    </div>
  );
}
