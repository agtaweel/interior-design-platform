"use client";

/**
 * BRD v4 "Client Marketplace" Phase C — a marketplace client's landing page: at-a-glance counts
 * plus quick links into Projects/Deals/Messages. Deliberately lightweight (no charts/financials
 * summary) — the detail lives on each section's own page, same "dashboard is an index, not a
 * second copy of the data" posture as the staff Dashboard.
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { useClientAuth } from "@/lib/auth/ClientAuthContext";
import { listClientProjects } from "@/lib/api/resources/clientProjects";
import { listClientDeals } from "@/lib/api/resources/clientDeals";
import { listClientConversations } from "@/lib/api/resources/conversations";
import { Card, CardBody } from "@/components/ui/Card";
import { LoadingScreen } from "@/components/ui/LoadingScreen";

export default function ClientDashboardPage() {
  const { client } = useClientAuth();
  const [counts, setCounts] = useState<{ projects: number; deals: number; conversations: number } | null>(null);

  useEffect(() => {
    let cancelled = false;
    Promise.all([listClientProjects(), listClientDeals(), listClientConversations()])
      .then(([projects, deals, conversations]) => {
        if (cancelled) return;
        setCounts({ projects: projects.length, deals: deals.length, conversations: conversations.length });
      })
      .catch(() => {
        if (!cancelled) setCounts({ projects: 0, deals: 0, conversations: 0 });
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
          Welcome{client ? `, ${client.name}` : ""}
        </h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
          Here&apos;s what&apos;s happening with your projects.
        </p>
      </div>

      {counts === null ? (
        <LoadingScreen label="Loading…" />
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Link href="/client/projects">
            <Card>
              <CardBody>
                <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                  Projects
                </p>
                <p className="mt-1 text-3xl font-semibold text-zinc-900 dark:text-zinc-50">{counts.projects}</p>
              </CardBody>
            </Card>
          </Link>
          <Link href="/client/deals">
            <Card>
              <CardBody>
                <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                  Deals
                </p>
                <p className="mt-1 text-3xl font-semibold text-zinc-900 dark:text-zinc-50">{counts.deals}</p>
              </CardBody>
            </Card>
          </Link>
          <Link href="/client/messages">
            <Card>
              <CardBody>
                <p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                  Conversations
                </p>
                <p className="mt-1 text-3xl font-semibold text-zinc-900 dark:text-zinc-50">
                  {counts.conversations}
                </p>
              </CardBody>
            </Card>
          </Link>
        </div>
      )}

      <Card>
        <CardBody>
          <p className="text-sm text-zinc-600 dark:text-zinc-300">
            Looking for a new studio?{" "}
            <Link href="/marketplace" className="font-medium text-amber-600 hover:underline dark:text-amber-400">
              Browse the marketplace
            </Link>
            .
          </p>
        </CardBody>
      </Card>
    </div>
  );
}
