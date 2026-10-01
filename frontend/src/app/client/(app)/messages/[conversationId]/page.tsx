"use client";

/**
 * BRD v4 "Client Marketplace" Phase B — a single conversation thread, client side. Same
 * 60s-poll pattern as the conversations list; sending a message does an optimistic local append
 * then reconciles on the next poll/explicit reload, matching NotificationBell's optimistic
 * read-marking pattern.
 */

import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import {
  listClientConversationMessages,
  postClientConversationMessage,
} from "@/lib/api/resources/conversations";
import type { ChatMessage } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { Button } from "@/components/ui/Button";
import { formatDateTime } from "@/lib/format/date";

const POLL_INTERVAL_MS = 60_000;

export default function ClientConversationThreadPage() {
  const params = useParams<{ conversationId: string }>();
  const [messages, setMessages] = useState<ChatMessage[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [body, setBody] = useState("");
  const [sending, setSending] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);

  const load = useCallback(async () => {
    try {
      setMessages(await listClientConversationMessages(params.conversationId));
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this conversation.");
    }
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
  }, [messages]);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const text = body.trim();
    if (!text) return;
    setSending(true);
    try {
      const message = await postClientConversationMessage(params.conversationId, text);
      setMessages((prev) => [...(prev ?? []), message]);
      setBody("");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not send your message.");
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <Link href="/client/messages" className="text-sm text-zinc-500 hover:underline dark:text-zinc-400">
        ← All conversations
      </Link>

      {error ? <ErrorBanner message={error} onRetry={load} retryLabel="Retry" /> : null}

      {messages === null && !error ? (
        <LoadingScreen label="Loading…" />
      ) : (
        <>
          <div className="flex min-h-[50vh] flex-col gap-3 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            {messages && messages.length === 0 ? (
              <p className="text-sm text-zinc-500 dark:text-zinc-400">
                Say hello to start the conversation.
              </p>
            ) : (
              messages?.map((m) => (
                <div
                  key={m.id}
                  className={`max-w-[75%] rounded-lg px-3 py-2 text-sm ${
                    m.sender_type === "client"
                      ? "ms-auto bg-amber-500 text-white dark:bg-amber-500 dark:text-zinc-950"
                      : "bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-zinc-50"
                  }`}
                >
                  <p className="whitespace-pre-line">{m.body}</p>
                  <p
                    className={`mt-1 text-[10px] ${
                      m.sender_type === "client" ? "text-amber-50/80" : "text-zinc-400"
                    }`}
                  >
                    {formatDateTime(m.created_at)}
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
              placeholder="Type a message…"
              className="flex-1 resize-none rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950"
            />
            <Button type="submit" disabled={sending || !body.trim()}>
              {sending ? "Sending…" : "Send"}
            </Button>
          </form>
        </>
      )}
    </div>
  );
}
