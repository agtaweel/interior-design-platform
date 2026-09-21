import type { ReactNode } from "react";

const TONE_CLASSES: Record<string, string> = {
  neutral: "bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300",
  green: "bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300",
  blue: "bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300",
  amber: "bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300",
  red: "bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300",
};

const STATUS_TONE: Record<string, keyof typeof TONE_CLASSES> = {
  active: "green",
  draft: "neutral",
  on_hold: "amber",
  completed: "blue",
  cancelled: "red",
  new: "blue",
  contacted: "amber",
  qualified: "blue",
  proposal_sent: "amber",
  won: "green",
  lost: "red",
};

export function Badge({ children, tone = "neutral" }: { children: ReactNode; tone?: keyof typeof TONE_CLASSES }) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${TONE_CLASSES[tone]}`}
    >
      {children}
    </span>
  );
}

/** Maps a known project/lead status string to a sensible badge tone, defaulting to neutral. */
export function StatusBadge({ status }: { status: string }) {
  const tone = STATUS_TONE[status] ?? "neutral";
  return <Badge tone={tone}>{status.replace(/_/g, " ")}</Badge>;
}
