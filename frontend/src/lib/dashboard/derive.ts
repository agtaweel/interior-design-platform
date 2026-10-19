/**
 * Pure helpers behind the S02 dashboard. Everything the screen shows twice (a count in the title
 * row's finding and the same count in a block) is computed once here and read from the same
 * value, so the two can never disagree.
 */

import type { Lead, LeadStatus, Project } from "@/lib/api/types";

const DAY_MS = 24 * 60 * 60 * 1000;

/** Today's calendar date in Cairo as "YYYY-MM-DD" — business dates are Cairo dates (see
 *  lib/format/date.ts), so "days late" must not drift by a day around UTC midnight. */
export function cairoToday(now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Africa/Cairo",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(now);
}

/** Whole days from `today` to `date` (both "YYYY-MM-DD"): negative = in the past. */
export function daysUntil(date: string, today: string): number {
  const a = Date.UTC(+today.slice(0, 4), +today.slice(5, 7) - 1, +today.slice(8, 10));
  const b = Date.UTC(+date.slice(0, 4), +date.slice(5, 7) - 1, +date.slice(8, 10));
  return Math.round((b - a) / DAY_MS);
}

export interface FinishRow {
  project: Project;
  /** Negative when the target finish has passed. */
  days: number;
}

/** Active / on-hold projects with a target finish, soonest (most overdue) first. */
export function finishSchedule(projects: Project[], today: string): FinishRow[] {
  return projects
    .filter((p) => (p.status === "active" || p.status === "on_hold") && p.target_end_date)
    .map((project) => ({ project, days: daysUntil(project.target_end_date!.slice(0, 10), today) }))
    .sort((a, b) => a.days - b.days);
}

export interface CollectionRow {
  project: Project;
  value: number;
  collected: number;
  outstanding: number;
  /** collected / value, 0..1; null when there is no contract value to measure against. */
  ratio: number | null;
}

/** Projects with money under contract (any status but cancelled), most left to collect first.
 *  Not filtered to "active": money is owed on drafts and handed-over jobs too, and this list has
 *  to explain the "Left to collect" KPI above it. Active projects with no contract yet follow,
 *  so a new job on site still shows up here.
 *
 *  `financials.value` is the priced estimate, not the contract value, while `outstanding` is
 *  contract_value − collected (ProjectFinancialsCalculator). So the contract basis shown is
 *  collected + outstanding — then the three figures on a row always add up on screen. (An
 *  overpaid contract has outstanding clamped to 0 server-side and simply reads as fully paid.) */
export function collectionRows(projects: Project[]): CollectionRow[] {
  return projects
    .map((project) => {
      const collected = Number(project.financials?.collected ?? 0);
      const outstanding = Number(project.financials?.outstanding ?? 0);
      const value = collected + outstanding;
      const ratio = value > 0 ? collected / value : null;
      return { project, value, collected, outstanding, ratio };
    })
    .filter((row) => row.project.status !== "cancelled" && (row.value > 0 || row.project.status === "active"))
    .sort((a, b) => {
      if ((a.ratio === null) !== (b.ratio === null)) return a.ratio === null ? 1 : -1;
      return b.outstanding - a.outstanding;
    });
}

export const OPEN_LEAD_STAGES: LeadStatus[] = ["new", "contacted", "qualified"];

export interface LeadStage {
  status: LeadStatus;
  count: number;
  budget: number;
}

/** Open-pipeline counts and summed estimated budgets per stage, in pipeline order. */
export function leadStages(leads: Lead[]): LeadStage[] {
  const stages = OPEN_LEAD_STAGES.map((status) => ({ status, count: 0, budget: 0 }));
  for (const lead of leads) {
    const stage = stages.find((s) => s.status === lead.status);
    if (!stage) continue;
    stage.count += 1;
    stage.budget += Number(lead.estimated_budget ?? 0);
  }
  return stages;
}
