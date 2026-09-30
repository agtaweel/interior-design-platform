import { Card } from "@/components/ui/Card";

// Hex, not Tailwind color utilities: Card's own `border` shorthand sets border-top-color too, and
// its `dark:border-zinc-800` variant reliably wins the cascade over a plain `border-t-{color}`
// utility regardless of class order in the DOM. An inline style can't lose that fight.
const TONE_HEX: Record<string, string> = {
  amber: "#f59e0b",
  blue: "#3b82f6",
  green: "#10b981",
  violet: "#8b5cf6",
  rose: "#f43f5e",
  neutral: "#a1a1aa",
};

interface KpiCardProps {
  label: string;
  value: string;
  hint?: string;
  tone?: keyof typeof TONE_HEX;
}

/** A single dashboard KPI tile (S02). Stub metrics render "—" from the caller side. Each tone is
 * just a top accent + label color — enough to let a row of KPI cards read as distinct metrics at
 * a glance without tinting the whole card. */
export function KpiCard({ label, value, hint, tone = "neutral" }: KpiCardProps) {
  return (
    <Card className="border-t-4 p-4" style={{ borderTopColor: TONE_HEX[tone] }}>
      <p className="text-sm font-medium text-zinc-500 dark:text-zinc-400">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{value}</p>
      {hint ? <p className="mt-1 text-xs text-zinc-400">{hint}</p> : null}
    </Card>
  );
}
