"use client";

import type { Lead, LeadStatus } from "@/lib/api/types";
import { Card } from "@/components/ui/Card";
import { formatEgyptianPhone } from "@/lib/format/phone";
import { useMoneyFormatter } from "@/lib/format/useMoneyFormatter";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

/** Statuses a lead can be moved directly between via the dropdown — "converted" is
 *  deliberately excluded, matching UpdateLeadRequest's server-side restriction: that
 *  transition only ever happens through the dedicated Convert action. */
const MOVABLE_STATUSES: Exclude<LeadStatus, "converted">[] = ["new", "contacted", "qualified", "lost"];

interface LeadCardProps {
  lead: Lead;
  onStatusChange: (lead: Lead, status: Exclude<LeadStatus, "converted">) => void;
  onConvert: (lead: Lead) => void;
  changingStatus?: boolean;
}

export function LeadCard({ lead, onStatusChange, onConvert, changingStatus }: LeadCardProps) {
  const { t } = useLocale();
  const { formatMoneyOrDash } = useMoneyFormatter();
  const isConverted = lead.status === "converted";

  return (
    <Card className="flex flex-col gap-2 p-3">
      <div>
        <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{lead.name}</p>
        {lead.phone ? (
          <p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
            {formatEgyptianPhone(lead.phone)}
          </p>
        ) : null}
      </div>

      <div className="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
        <span>{lead.source ?? t("common.na")}</span>
        <span className="font-medium text-zinc-700 dark:text-zinc-300">
          {formatMoneyOrDash(lead.estimated_budget)}
        </span>
      </div>

      {lead.owner ? <p className="text-xs text-zinc-400">{lead.owner.name}</p> : null}

      {isConverted ? (
        <p className="text-xs font-medium text-green-700 dark:text-green-400">
          {t("leads.card.converted")}
        </p>
      ) : (
        <div className="flex flex-col gap-1.5 border-t border-zinc-100 pt-2 dark:border-zinc-800">
          <select
            value={lead.status}
            disabled={changingStatus}
            onChange={(e) => onStatusChange(lead, e.target.value as Exclude<LeadStatus, "converted">)}
            className="w-full rounded border border-zinc-300 bg-white px-1.5 py-1 text-xs disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-950"
          >
            {MOVABLE_STATUSES.map((status) => (
              <option key={status} value={status}>
                {t(`leads.column.${status}` as TranslationKey)}
              </option>
            ))}
          </select>
          <button
            type="button"
            onClick={() => onConvert(lead)}
            className="text-left text-xs font-medium text-zinc-700 underline underline-offset-2 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100"
          >
            {t("leads.card.convert")}
          </button>
        </div>
      )}
    </Card>
  );
}
