"use client";

/**
 * S03 — Leads.
 *
 * TODO(backend): there is no `leads` API yet (see lib/leads/mock.ts for the full explanation
 * and the working-agreement citation). This screen is the shell — pipeline columns by status,
 * a lead card, and filters UI — wired to the typed mock data source so the layout/interaction
 * pattern is validated now and only the data-fetching call needs to change once
 * `lib/api/resources/leads.ts` exists.
 */

import { useEffect, useMemo, useState } from "react";
import {
  getMockLeads,
  LEAD_PIPELINE_STATUSES,
  type Lead,
  type LeadStatus,
} from "@/lib/leads/mock";
import { LeadCard } from "@/components/leads/LeadCard";
import { LoadingScreen } from "@/components/ui/LoadingScreen";
import { EmptyState } from "@/components/ui/EmptyState";
import { useLocale } from "@/lib/i18n/LocaleProvider";

export default function LeadsPage() {
  const { t } = useLocale();
  const [leads, setLeads] = useState<Lead[] | null>(null);
  const [search, setSearch] = useState("");
  const [sourceFilter, setSourceFilter] = useState<string>("all");
  const [ownerFilter, setOwnerFilter] = useState<string>("all");

  useEffect(() => {
    getMockLeads().then(setLeads);
  }, []);

  const sources = useMemo(
    () => Array.from(new Set((leads ?? []).map((l) => l.source))).sort(),
    [leads],
  );
  const owners = useMemo(
    () => Array.from(new Set((leads ?? []).map((l) => l.owner))).sort(),
    [leads],
  );

  const filtered = useMemo(() => {
    if (!leads) return [];
    const needle = search.trim().toLowerCase();
    return leads.filter((lead) => {
      if (needle && !lead.name.toLowerCase().includes(needle) && !lead.phone.includes(needle)) {
        return false;
      }
      if (sourceFilter !== "all" && lead.source !== sourceFilter) return false;
      if (ownerFilter !== "all" && lead.owner !== ownerFilter) return false;
      return true;
    });
  }, [leads, search, sourceFilter, ownerFilter]);

  const byStatus = useMemo(() => {
    const map = new Map<LeadStatus, Lead[]>();
    for (const status of LEAD_PIPELINE_STATUSES) map.set(status, []);
    for (const lead of filtered) {
      map.get(lead.status)?.push(lead);
    }
    return map;
  }, [filtered]);

  if (leads === null) {
    return <LoadingScreen label={t("common.loading")} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{t("leads.title")}</h1>
        <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{t("leads.subtitle")}</p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <input
          type="search"
          placeholder={t("leads.filters.search")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900"
        />
        <select
          value={sourceFilter}
          onChange={(e) => setSourceFilter(e.target.value)}
          className="rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
          aria-label={t("leads.filters.source")}
        >
          <option value="all">{t("leads.filters.source")}: All</option>
          {sources.map((source) => (
            <option key={source} value={source}>
              {source}
            </option>
          ))}
        </select>
        <select
          value={ownerFilter}
          onChange={(e) => setOwnerFilter(e.target.value)}
          className="rounded-md border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
          aria-label={t("leads.filters.owner")}
        >
          <option value="all">{t("leads.filters.owner")}: All</option>
          {owners.map((owner) => (
            <option key={owner} value={owner}>
              {owner}
            </option>
          ))}
        </select>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        {LEAD_PIPELINE_STATUSES.map((status) => {
          const columnLeads = byStatus.get(status) ?? [];
          return (
            <div key={status} className="flex flex-col gap-2">
              <div className="flex items-center justify-between px-1">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                  {t(`leads.column.${status}`)}
                </h2>
                <span className="text-xs text-zinc-400">{columnLeads.length}</span>
              </div>
              <div className="flex flex-col gap-2">
                {columnLeads.length === 0 ? (
                  <EmptyState message="—" />
                ) : (
                  columnLeads.map((lead) => <LeadCard key={lead.id} lead={lead} />)
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
