/**
 * Building blocks of the S02 dashboard's "ledger" look (tokens in globals.css under `.ledger`):
 * flat white panels with one 1px edge and no shadow, KPIs as one divided strip rather than
 * tiles, the brand amber only on collected money, pale same-hue status pills.
 * Logical properties (ps/pe/text-end) throughout so the Arabic RTL layout mirrors cleanly.
 */

import Link from "next/link";
import type { ReactNode } from "react";

export function Panel({
  title,
  meta,
  action,
  children,
  className = "",
}: {
  title: string;
  meta?: ReactNode;
  action?: { href: string; label: string };
  children: ReactNode;
  className?: string;
}) {
  return (
    <section
      className={`flex flex-col rounded-lg border border-[var(--l-border)] bg-[var(--l-surface)] ${className}`}
    >
      <header className="flex items-baseline justify-between gap-4 px-5 pt-4 pb-3">
        <div className="min-w-0">
          <h2 className="text-base font-medium">{title}</h2>
          {meta ? <p className="mt-0.5 text-xs text-[var(--l-muted)]">{meta}</p> : null}
        </div>
        {action ? (
          <Link
            href={action.href}
            className="shrink-0 rounded text-sm text-[var(--l-muted)] hover:text-[var(--l-text)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--l-text)]"
          >
            {action.label}
          </Link>
        ) : null}
      </header>
      <div className="flex-1 px-5 pb-4">{children}</div>
    </section>
  );
}

export interface KpiCell {
  label: string;
  value: ReactNode;
  note?: ReactNode;
  /** The screen's answer figure: larger and bold (money only). */
  lead?: boolean;
}

/** One container split by 1px dividers — four columns on desktop, 2×2 on a tablet, a divided list on a phone (the lead money figure does not fit a half-width phone cell). */
export function KpiStrip({ cells }: { cells: KpiCell[] }) {
  return (
    <div className="grid grid-cols-1 gap-px overflow-hidden rounded-lg border border-[var(--l-border)] bg-[var(--l-border)] sm:grid-cols-2 lg:grid-cols-4">
      {cells.map((cell) => (
        <div key={cell.label} className="bg-[var(--l-surface)] px-5 py-4">
          <p className="text-sm text-[var(--l-muted)]">{cell.label}</p>
          <p
            className={`mt-1.5 tabular-nums leading-tight ${
              cell.lead ? "text-[26px] font-bold tracking-[-0.01em] sm:text-[30px]" : "text-2xl font-normal"
            }`}
          >
            {cell.value}
          </p>
          {cell.note ? <p className="mt-1 text-xs text-[var(--l-muted)]">{cell.note}</p> : null}
        </div>
      ))}
    </div>
  );
}

/** Collected against contract: the amber fill is the true ratio, the grey track is the rest. */
export function Track({ ratio, label }: { ratio: number; label: string }) {
  return (
    <div
      role="meter"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={Math.round(ratio * 100)}
      className="flex h-2 w-full overflow-hidden rounded-full bg-[var(--l-track)]"
    >
      {ratio > 0 ? (
        <div className="h-full rounded-full bg-[var(--l-data)]" style={{ width: `${ratio * 100}%` }} />
      ) : null}
    </div>
  );
}

const PILL_TONE: Record<string, string> = {
  active: "bg-[var(--l-pos-bg)] text-[var(--l-pos-ink)]",
  on_hold: "bg-[var(--l-warn-bg)] text-[var(--l-warn-ink)]",
  cancelled: "bg-[var(--l-neg-bg)] text-[var(--l-neg-ink)]",
};

export function StatusPill({ status, label }: { status: string; label: string }) {
  return (
    <span
      className={`inline-flex h-5 flex-none items-center whitespace-nowrap rounded px-1.5 text-xs font-medium ${
        PILL_TONE[status] ?? "bg-[var(--l-neutral-bg)] text-[var(--l-neutral-ink)]"
      }`}
    >
      {label}
    </span>
  );
}

/** Loading placeholder at the real size of what it stands in for — no shimmer, no layout shift. */
export function Bar({ className }: { className: string }) {
  return <span aria-hidden="true" className={`block rounded bg-[var(--l-raised)] ${className}`} />;
}

/** A failed block keeps its frame: one sentence and a Retry, never a full-screen banner. */
export function BlockError({ message, onRetry, retryLabel }: { message: string; onRetry: () => void; retryLabel: string }) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-md bg-[var(--l-raised)] px-3 py-2.5 text-sm">
      <span>{message}</span>
      <button
        type="button"
        onClick={onRetry}
        className="shrink-0 font-medium underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-[var(--l-text)]"
      >
        {retryLabel}
      </button>
    </div>
  );
}

export function EmptyLine({ children }: { children: ReactNode }) {
  return (
    <p className="rounded-md border border-dashed border-[var(--l-border)] px-3 py-2.5 text-sm text-[var(--l-muted)]">
      {children}
    </p>
  );
}
