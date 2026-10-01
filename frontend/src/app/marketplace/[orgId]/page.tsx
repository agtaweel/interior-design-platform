"use client";

/**
 * BRD v4 "Client Marketplace" — single organization's public profile + portfolio. The
 * "Start a conversation" CTA routes to /client/signup if the visitor has no account yet;
 * once authenticated it calls POST /client/conversations (find-or-create — see
 * ConversationService::startConversation()'s docblock) and routes straight into that thread.
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import {
  getMarketplaceOrganization,
  marketplaceOrganizationMediaUrl,
} from "@/lib/api/resources/marketplace";
import { startClientConversation } from "@/lib/api/resources/conversations";
import type { PublicOrganizationDetail } from "@/lib/api/types";
import { useClientAuth } from "@/lib/auth/ClientAuthContext";
import { FitoutLogo } from "@/components/brand/FitoutLogo";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { Button } from "@/components/ui/Button";

export default function MarketplaceOrganizationPage() {
  const params = useParams<{ orgId: string }>();
  const router = useRouter();
  const { status } = useClientAuth();
  const [organization, setOrganization] = useState<PublicOrganizationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);

  async function load() {
    setLoading(true);
    setError(null);
    try {
      setOrganization(await getMarketplaceOrganization(params.orgId));
    } catch {
      setError("Could not load this studio's profile.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.orgId]);

  async function handleStartConversation() {
    if (status !== "authenticated") {
      router.push(`/client/signup?organization_id=${params.orgId}`);
      return;
    }
    setStarting(true);
    try {
      const conversation = await startClientConversation(params.orgId);
      router.push(`/client/messages/${conversation.id}`);
    } catch {
      setError("Could not start a conversation. Please try again.");
      setStarting(false);
    }
  }

  return (
    <div className="min-h-screen bg-zinc-50 dark:bg-black">
      <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <div className="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-3">
          <Link href="/marketplace">
            <FitoutLogo />
          </Link>
          <ThemeToggle />
        </div>
      </header>

      <main className="mx-auto max-w-4xl px-4 py-10">
        {loading ? (
          <LoadingScreen label="Loading…" />
        ) : error || !organization ? (
          <ErrorBanner message={error ?? "Studio not found."} onRetry={load} retryLabel="Retry" />
        ) : (
          <>
            <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
              <div>
                <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">
                  {organization.name}
                </h1>
                {organization.service_area ? (
                  <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {organization.service_area}
                  </p>
                ) : null}
              </div>
              <Button onClick={handleStartConversation} disabled={starting}>
                {starting ? "Starting…" : "Start a conversation"}
              </Button>
            </div>

            {organization.services_offered.length > 0 ? (
              <div className="mt-4 flex flex-wrap gap-2">
                {organization.services_offered.map((service) => (
                  <span
                    key={service}
                    className="rounded-full bg-zinc-100 px-3 py-1 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                  >
                    {service}
                  </span>
                ))}
              </div>
            ) : null}

            {organization.description ? (
              <p className="mt-6 whitespace-pre-line text-zinc-700 dark:text-zinc-300">
                {organization.description}
              </p>
            ) : null}

            {organization.portfolio.length > 0 ? (
              <div className="mt-8">
                <h2 className="mb-3 text-sm font-semibold text-zinc-900 dark:text-zinc-50">Portfolio</h2>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  {organization.portfolio.map((media) => (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      key={media.id}
                      src={marketplaceOrganizationMediaUrl(organization.id, media.id)}
                      alt={media.file_name}
                      className="aspect-square w-full rounded-md object-cover"
                    />
                  ))}
                </div>
              </div>
            ) : null}
          </>
        )}
      </main>
    </div>
  );
}
