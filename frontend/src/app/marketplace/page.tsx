"use client";

/**
 * BRD v4 "Client Marketplace" — public browse grid, no auth required. Lives OUTSIDE the
 * `(protected)` route group (same isolation as the client-portal/proposal public pages) and
 * outside `/client/*` too, since browsing itself needs no ClientUser session — only acting on a
 * listing (starting a conversation) will.
 */

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { listMarketplaceOrganizations } from "@/lib/api/resources/marketplace";
import { marketplaceOrganizationMediaUrl } from "@/lib/api/resources/marketplace";
import type { PublicOrganizationSummary } from "@/lib/api/types";
import { FitoutLogo } from "@/components/brand/FitoutLogo";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { ErrorBanner } from "@/components/ui/ErrorBanner";
import { Card } from "@/components/ui/Card";

export default function MarketplacePage() {
  const [organizations, setOrganizations] = useState<PublicOrganizationSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  async function load(q?: string) {
    setLoading(true);
    setError(null);
    try {
      const result = await listMarketplaceOrganizations({ q });
      setOrganizations(result.data);
    } catch {
      setError("Could not load organizations. Please try again.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleSearch(e: FormEvent) {
    e.preventDefault();
    load(search);
  }

  return (
    <div className="min-h-screen bg-zinc-50 dark:bg-black">
      <header className="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
          <FitoutLogo />
          <div className="flex items-center gap-3">
            <ThemeToggle />
            <Link
              href="/client/login"
              className="text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
            >
              Log in
            </Link>
            <Link href="/client/signup">
              <span className="inline-flex items-center justify-center rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 dark:bg-amber-500 dark:text-zinc-950 dark:hover:bg-amber-400">
                Sign up
              </span>
            </Link>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-4 py-10">
        <div className="mb-8 text-center">
          <h1 className="text-3xl font-semibold text-zinc-900 dark:text-zinc-50">
            Find your interior design studio
          </h1>
          <p className="mt-2 text-zinc-500 dark:text-zinc-400">
            Browse studios, start a conversation, and get a deal for your project.
          </p>
        </div>

        <form onSubmit={handleSearch} className="mx-auto mb-8 flex max-w-md gap-2">
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search studios by name…"
            className="flex-1 rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-950"
          />
          <button
            type="submit"
            className="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 dark:bg-amber-500 dark:text-zinc-950 dark:hover:bg-amber-400"
          >
            Search
          </button>
        </form>

        {error ? <ErrorBanner message={error} onRetry={() => load(search)} retryLabel="Retry" /> : null}

        {loading ? (
          <LoadingScreen label="Loading studios…" />
        ) : organizations.length === 0 ? (
          <EmptyState message="No studios found." />
        ) : (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {organizations.map((org) => (
              <Link key={org.id} href={`/marketplace/${org.id}`}>
                <Card className="h-full overflow-hidden transition-shadow hover:shadow-md">
                  <div className="flex h-36 items-center justify-center bg-zinc-100 dark:bg-zinc-800">
                    {org.cover_media_id ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={marketplaceOrganizationMediaUrl(org.id, org.cover_media_id)}
                        alt={org.name}
                        className="h-full w-full object-cover"
                      />
                    ) : (
                      <span className="text-sm text-zinc-400">No photo yet</span>
                    )}
                  </div>
                  <div className="p-4">
                    <h2 className="font-semibold text-zinc-900 dark:text-zinc-50">{org.name}</h2>
                    {org.service_area ? (
                      <p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{org.service_area}</p>
                    ) : null}
                    {org.description ? (
                      <p className="mt-2 line-clamp-2 text-sm text-zinc-600 dark:text-zinc-300">
                        {org.description}
                      </p>
                    ) : null}
                    {org.services_offered.length > 0 ? (
                      <div className="mt-3 flex flex-wrap gap-1.5">
                        {org.services_offered.slice(0, 3).map((service) => (
                          <span
                            key={service}
                            className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                          >
                            {service}
                          </span>
                        ))}
                      </div>
                    ) : null}
                  </div>
                </Card>
              </Link>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
