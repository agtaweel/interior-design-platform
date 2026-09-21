import type { Lead } from "@/lib/leads/mock";
import { Card } from "@/components/ui/Card";
import { formatEGP } from "@/lib/format/currency";
import { formatEgyptianPhone } from "@/lib/format/phone";
import { useLocale } from "@/lib/i18n/LocaleProvider";

export function LeadCard({ lead }: { lead: Lead }) {
  const { locale } = useLocale();

  return (
    <Card className="p-3">
      <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{lead.name}</p>
      <p className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
        {formatEgyptianPhone(lead.phone)}
      </p>
      <div className="mt-2 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
        <span>{lead.source}</span>
        <span className="font-medium text-zinc-700 dark:text-zinc-300">
          {formatEGP(lead.estimatedValue, locale)}
        </span>
      </div>
      <p className="mt-1 text-xs text-zinc-400">{lead.owner}</p>
    </Card>
  );
}
