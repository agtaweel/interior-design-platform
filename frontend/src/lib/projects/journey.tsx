"use client";

/**
 * GET /projects/{id}/journey — the project's stage and single next step (backend:
 * App\Services\Projects\ProjectJourney). Loaded once by the project layout and shared through
 * context with the tab bar (stage grouping) and the overview ("Next step" card), so the two never
 * disagree. Re-fetched whenever the user moves between project tabs, which is when an action on
 * one tab (sending a proposal, recording a handover…) can have changed it.
 */

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { apiGetResource } from "@/lib/api/client";

export type ProjectStage = "quote" | "agreement" | "work" | "finish" | "done";

export const STAGE_ORDER: ProjectStage[] = ["quote", "agreement", "work", "finish", "done"];

export type NextStepKey =
  | "add_items"
  | "create_proposal"
  | "send_proposal"
  | "await_client"
  | "revise_proposal"
  | "convert_to_contract"
  | "set_installments"
  | "apply_change_order"
  | "track_work"
  | "close_snags"
  | "record_handover"
  | "handed_over";

export interface ProjectJourney {
  stage: ProjectStage;
  /** `tab` is a project route segment ("" = overview). */
  next_step: { key: NextStepKey; tab: string };
  facts: Record<string, number | string | boolean | null>;
}

export function getProjectJourney(projectId: string | number) {
  return apiGetResource<ProjectJourney>(`/projects/${projectId}/journey`);
}

const JourneyContext = createContext<ProjectJourney | null>(null);

export function ProjectJourneyProvider({
  projectId,
  refreshKey,
  children,
}: {
  projectId: string;
  /** Changing this re-fetches (the layout passes the current pathname). */
  refreshKey: string;
  children: ReactNode;
}) {
  const [journey, setJourney] = useState<ProjectJourney | null>(null);

  useEffect(() => {
    let cancelled = false;
    getProjectJourney(projectId)
      .then((data) => !cancelled && setJourney(data))
      // Purely a guidance layer — if it fails, the tabs simply render ungraded.
      .catch(() => !cancelled && setJourney(null));
    return () => {
      cancelled = true;
    };
  }, [projectId, refreshKey]);

  return <JourneyContext.Provider value={journey}>{children}</JourneyContext.Provider>;
}

/** null while loading or if the journey couldn't be fetched. */
export function useProjectJourney(): ProjectJourney | null {
  return useContext(JourneyContext);
}
