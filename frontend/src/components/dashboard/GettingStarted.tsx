"use client";

/**
 * Dashboard "Getting started" checklist — the four steps from an empty account to a first
 * proposal on a client's phone. Every tick is derived from real data, never self-reported:
 *  - client:  GET /clients meta.total > 0 (fetched here — the dashboard doesn't otherwise need it)
 *  - project: the dashboard's GET /projects total > 0
 *  - price:   some project has a priced value (financials.value is the cached grand_total, kept
 *             current automatically whenever BOQ items or pricing rules change)
 *  - send:    a proposal is out with a client, or a project already has a contract/payments
 *             (outstanding/collected > 0) — covers accounts whose proposal was already approved,
 *             and roles without VIEW_FINANCIALS where `summary` is null.
 * Renders nothing until the data is in (no flash for established accounts), once every step is
 * done, or after "Hide" (remembered per browser).
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { listClients } from "@/lib/api/resources/clients";
import type { Project, ReportSummary } from "@/lib/api/types";
import { useLocale } from "@/lib/i18n/LocaleProvider";
import { GETTING_STARTED as G, pick, type Localized } from "@/lib/guide/content";

const DISMISS_KEY = "idp.gettingStarted.dismissed";

function readDismissed(): boolean {
  try {
    return window.localStorage.getItem(DISMISS_KEY) === "1";
  } catch {
    return false;
  }
}

export function GettingStarted({
  projects,
  projectTotal,
  summary,
}: {
  /** undefined while the dashboard's project list is still loading. */
  projects: Project[] | undefined;
  projectTotal: number | undefined;
  summary: ReportSummary | null | undefined;
}) {
  const { locale } = useLocale();
  const p = (text: Localized) => pick(text, locale);
  const num = (n: number) => new Intl.NumberFormat(locale === "ar" ? "ar-EG" : "en-EG").format(n);

  const [clientTotal, setClientTotal] = useState<number | undefined>(undefined);
  const [dismissed, setDismissed] = useState(true);

  useEffect(() => {
    // localStorage is client-only; read after mount to keep SSR markup stable.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setDismissed(readDismissed());
    listClients()
      .then((r) => setClientTotal(r.meta.total))
      // A failed count shouldn't block the checklist — treat it as "not done yet".
      .catch(() => setClientTotal(0));
  }, []);

  if (dismissed || projects === undefined || projectTotal === undefined || clientTotal === undefined || summary === undefined) {
    return null;
  }

  const firstProject = projects[0];
  const priced = projects.find((proj) => Number(proj.financials.value) > 0);
  const sent =
    (summary?.pending_proposals_count ?? 0) > 0 ||
    projects.some((proj) => Number(proj.financials.outstanding) > 0 || Number(proj.financials.collected) > 0);

  const projectHref = (suffix = "") => (firstProject ? `/projects/${(priced ?? firstProject).id}${suffix}` : "/projects?new=1");

  const steps = [
    { key: "client", done: clientTotal > 0, href: "/clients", copy: G.steps.client },
    { key: "project", done: projectTotal > 0, href: "/projects?new=1", copy: G.steps.project },
    { key: "price", done: Boolean(priced), href: projectHref("/boq"), copy: G.steps.price },
    { key: "send", done: sent, href: projectHref("/proposal"), copy: G.steps.send },
  ];
  const doneCount = steps.filter((s) => s.done).length;
  if (doneCount === steps.length) return null;
  // The next step is the first undone one; later steps stay visible but quiet.
  const nextKey = steps.find((s) => !s.done)?.key;

  function dismiss() {
    setDismissed(true);
    try {
      window.localStorage.setItem(DISMISS_KEY, "1");
    } catch {
      // Private mode / blocked storage: it just reappears next visit.
    }
  }

  return (
    <section
      aria-labelledby="getting-started-title"
      className="rounded-lg border border-[var(--l-border)] bg-[var(--l-surface)]"
    >
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--l-border)] px-5 py-4">
        <div>
          <h2 id="getting-started-title" className="text-base font-semibold">
            {p(G.title)}
          </h2>
          <p className="mt-0.5 text-sm text-[var(--l-muted)]">
            {p(G.intro)}{" "}
            <span className="font-medium text-[var(--l-text)]">
              {p(G.progress).replace("{done}", num(doneCount)).replace("{total}", num(steps.length))}
            </span>
          </p>
        </div>
        <div className="flex items-center gap-4 text-sm">
          <Link href="/help" className="font-medium underline underline-offset-2 hover:no-underline">
            {p(G.fullGuide)}
          </Link>
          <button type="button" onClick={dismiss} className="text-[var(--l-muted)] hover:text-[var(--l-text)]">
            {p(G.dismiss)}
          </button>
        </div>
      </div>
      <ol className="grid divide-y divide-[var(--l-rule)] md:grid-cols-4 md:divide-x md:divide-y-0 rtl:md:divide-x-reverse">
        {steps.map((step, i) => {
          const isNext = step.key === nextKey;
          return (
            <li key={step.key} className="flex flex-col gap-2 px-5 py-4">
              <div className="flex items-center gap-2">
                <span
                  aria-hidden="true"
                  className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                    step.done
                      ? "bg-[var(--l-pos-bg)] text-[var(--l-pos-ink)]"
                      : isNext
                        ? "bg-[var(--l-primary)] text-[var(--l-primary-ink)]"
                        : "bg-[var(--l-raised)] text-[var(--l-muted)]"
                  }`}
                >
                  {step.done ? "✓" : num(i + 1)}
                </span>
                <h3 className={`text-sm font-semibold ${step.done ? "text-[var(--l-muted)] line-through" : ""}`}>
                  {p(step.copy.title)}
                </h3>
                {step.done ? <span className="sr-only">({p(G.doneLabel)})</span> : null}
              </div>
              {!step.done ? (
                <>
                  <p className="text-sm leading-relaxed text-[var(--l-muted)]">{p(step.copy.body)}</p>
                  {isNext ? (
                    <Link
                      href={step.href}
                      className="mt-auto inline-flex h-9 w-fit items-center rounded-md bg-[var(--l-primary)] px-3 text-sm font-medium text-[var(--l-primary-ink)] hover:opacity-90"
                    >
                      {p(step.copy.cta)}
                    </Link>
                  ) : null}
                </>
              ) : null}
            </li>
          );
        })}
      </ol>
    </section>
  );
}
