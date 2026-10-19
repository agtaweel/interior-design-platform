import type { Locale } from "@/lib/i18n/dictionaries";
import { pick, type GlossaryEntry } from "@/lib/guide/content";

/** Plain-language meanings for the trade terms the app uses — shared by /guide and /help. */
export function GlossaryList({
  entries,
  locale,
  className = "",
}: {
  entries: GlossaryEntry[];
  locale: Locale;
  className?: string;
}) {
  return (
    <dl className={`grid gap-x-8 gap-y-5 sm:grid-cols-2 ${className}`}>
      {entries.map((entry) => (
        <div key={entry.term.en}>
          <dt className="font-semibold">{pick(entry.term, locale)}</dt>
          <dd className="mt-1 text-sm leading-relaxed text-[var(--l-muted)]">{pick(entry.meaning, locale)}</dd>
        </div>
      ))}
    </dl>
  );
}
