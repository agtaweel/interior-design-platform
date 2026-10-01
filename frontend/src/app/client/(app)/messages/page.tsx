"use client";

/**
 * BRD v4 "Client Marketplace" Phase B — a marketplace client's own conversation list. Polling
 * mirrors NotificationBell.tsx's exact POLL_INTERVAL_MS = 60_000 setInterval structure (this
 * codebase's established "simple polling thread" pattern — see Conversation's migration
 * docblock), not real-time push.
 */

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { listClientConversations } from "@/lib/api/resources/conversations";
import type { ClientConversationSummary } from "@/lib/api/types";
import { ApiError } from "@/lib/api/client";
import { Card, CardBody } from "@/components/ui/Card";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { formatDateTime } from "@/lib/format/date";

const POLL_INTERVAL_MS = 60_000;

export default function ClientMessagesPage() {
  const [conversations, setConversations] = useState<ClientConversationSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setConversations(await listClientConversations());
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load your conversations.");
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  useEffect(() => {
    const interval = setInterval(load, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [load]);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">Messages</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          Conversations with the studios you have reached out to.
        </p>
      </div>

      {error ? <ErrorBanner message={error} onRetry={load} retryLabel="Retry" /> : null}

      {conversations === null && !error ? (
        <LoadingScreen label="Loading…" />
      ) : conversations && conversations.length === 0 ? (
        <EmptyState message="No conversations yet. Browse the marketplace to find a studio." />
      ) : (
        <Card>
          <CardBody className="p-0">
            <ul className="divide-y divide-zinc-100 dark:divide-zinc-800">
              {conversations?.map((c) => (
                <li key={c.id}>
                  <Link
                    href={`/client/messages/${c.id}`}
                    className="flex items-center justify-between gap-4 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-900"
                  >
                    <div className="min-w-0">
                      <p className="truncate font-medium text-zinc-900 dark:text-zinc-50">
                        {c.organization.name}
                      </p>
                      <p className="truncate text-sm text-zinc-500 dark:text-zinc-400">
                        {c.last_message ? c.last_message.body : "No messages yet."}
                      </p>
                    </div>
                    <span className="shrink-0 text-xs text-zinc-400">
                      {formatDateTime(c.updated_at)}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>
      )}
    </div>
  );
}
