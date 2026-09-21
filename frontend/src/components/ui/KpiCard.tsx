import { Card } from "@/components/ui/Card";

interface KpiCardProps {
  label: string;
  value: string;
  hint?: string;
}

/** A single dashboard KPI tile (S02). Stub metrics render "—" from the caller side. */
export function KpiCard({ label, value, hint }: KpiCardProps) {
  return (
    <Card className="p-4">
      <p className="text-sm font-medium text-zinc-500 dark:text-zinc-400">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-zinc-900 dark:text-zinc-50">{value}</p>
      {hint ? <p className="mt-1 text-xs text-zinc-400">{hint}</p> : null}
    </Card>
  );
}
