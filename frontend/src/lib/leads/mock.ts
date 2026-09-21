/**
 * TODO(backend): the `leads` table/API is not built yet (PRD MVP delivery order puts leads
 * pipeline management outside Sprint 1's Foundation scope, and it's absent from
 * docs/PROJECT_CONTEXT.md's Sprint 1 endpoint list). This module is a typed stand-in for the
 * eventual `GET /leads` response shape so the S03 screen can be built against something
 * concrete now and swapped for a real `lib/api/resources/leads.ts` later without changing any
 * component code — only this file's `getMockLeads()` implementation should need to change.
 *
 * Flagged to the supervisor per the frontend-engineer working agreement ("if a backend endpoint
 * isn't ready yet, build against a typed mock/stub... flag the dependency").
 */

export type LeadStatus =
  | "new"
  | "contacted"
  | "qualified"
  | "proposal_sent"
  | "won"
  | "lost";

export const LEAD_PIPELINE_STATUSES: LeadStatus[] = [
  "new",
  "contacted",
  "qualified",
  "proposal_sent",
  "won",
  "lost",
];

export interface Lead {
  id: string;
  name: string;
  phone: string;
  source: string;
  status: LeadStatus;
  owner: string;
  estimatedValue: number;
  createdAt: string;
}

const MOCK_LEADS: Lead[] = [
  {
    id: "lead-1",
    name: "Mona Abdelrahman",
    phone: "01012345678",
    source: "Facebook Ad",
    status: "new",
    owner: "Sara Youssef",
    estimatedValue: 250000,
    createdAt: "2026-09-18",
  },
  {
    id: "lead-2",
    name: "Karim El Sayed",
    phone: "01123456789",
    source: "Referral",
    status: "contacted",
    owner: "Ahmed Fathy",
    estimatedValue: 480000,
    createdAt: "2026-09-15",
  },
  {
    id: "lead-3",
    name: "Nour Hassan",
    phone: "01234567890",
    source: "Website",
    status: "qualified",
    owner: "Sara Youssef",
    estimatedValue: 620000,
    createdAt: "2026-09-10",
  },
  {
    id: "lead-4",
    name: "Tamer Adel",
    phone: "01555512345",
    source: "Instagram",
    status: "proposal_sent",
    owner: "Ahmed Fathy",
    estimatedValue: 900000,
    createdAt: "2026-09-05",
  },
  {
    id: "lead-5",
    name: "Yasmin Farouk",
    phone: "01098765432",
    source: "Referral",
    status: "won",
    owner: "Sara Youssef",
    estimatedValue: 1200000,
    createdAt: "2026-08-28",
  },
  {
    id: "lead-6",
    name: "Omar Zaki",
    phone: "01187654321",
    source: "Walk-in",
    status: "lost",
    owner: "Ahmed Fathy",
    estimatedValue: 300000,
    createdAt: "2026-08-20",
  },
];

/** Simulates an async API call so callers already shaped for a future real fetch. */
export async function getMockLeads(): Promise<Lead[]> {
  return MOCK_LEADS;
}
