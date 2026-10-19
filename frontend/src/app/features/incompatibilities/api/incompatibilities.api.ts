import { apiClient } from "../../../api/apiClient";

/** D-152 — surgeon–instrumentist incompatibility, as served by IncompatibilityController. */
export type IncompatibilityOrigin = "SURGEON_REFUSAL" | "SURGEON" | "MANAGER";

export type IncompatibilityConflict = {
  alertId: number;
  missionId: number;
  startAt: string | null;
  siteName: string | null;
};

export type Incompatibility = {
  id: number;
  surgeon: { id: number; name: string };
  instrumentist: { id: number; name: string };
  origin: IncompatibilityOrigin;
  active: boolean;
  createdAt: string;
  createdByName: string | null;
  liftedAt: string | null;
  liftedByName: string | null;
  /** Decided by the backend voter (manager: any; surgeon: his own blocks only). */
  canLift: boolean;
  /** Managers only: future missions still affected to the pair, to be resolved. */
  conflicts?: IncompatibilityConflict[];
};

export type PersonOption = { id: number; name: string };

/**
 * D-152 — a search result: those already blocked stay listed (`blocked`, with the active
 * incompatibility to lift), shown « Bloqué » and never offered a second block.
 */
export type BlockStatus = { blocked: boolean; incompatibilityId: number | null };

export const ORIGIN_LABELS: Record<IncompatibilityOrigin, string> = {
  SURGEON_REFUSAL: "Refus durable du chirurgien",
  SURGEON: "Blocage par le chirurgien",
  MANAGER: "Décision manager",
};

// ── Surgeon (own blocks) ─────────────────────────────────────────────────────

export async function fetchMyBlockedInstrumentists(): Promise<Incompatibility[]> {
  const res = await apiClient.get("/api/me/blocked-instrumentists");
  return (res.data?.items ?? []) as Incompatibility[];
}

export async function fetchBlockableInstrumentists(q: string): Promise<Array<PersonOption & BlockStatus>> {
  const res = await apiClient.get("/api/me/blockable-instrumentists", { params: { q } });
  return (res.data?.items ?? []) as Array<PersonOption & BlockStatus>;
}

export async function blockInstrumentist(instrumentistId: number): Promise<Incompatibility> {
  const res = await apiClient.post("/api/me/blocked-instrumentists", { instrumentistId });
  return res.data as Incompatibility;
}

// ── Manager ──────────────────────────────────────────────────────────────────

export async function fetchIncompatibilitiesOf(mode: "surgeon" | "instrumentist", personId: number): Promise<Incompatibility[]> {
  const res = await apiClient.get(`/api/${mode === "surgeon" ? "surgeons" : "instrumentists"}/${personId}/incompatibilities`);
  return (res.data?.items ?? []) as Incompatibility[];
}

export type CandidateOption = PersonOption & BlockStatus & { email: string };

export type CandidatePage = {
  items: CandidateOption[];
  page: number;
  limit: number;
  total: number;
  hasMore: boolean;
};

/**
 * D-152 (réserve 1) — the counterparts of this file's person, searched and paged by the
 * backend (never the whole list of accounts in the browser), those already blocked included.
 */
export async function searchIncompatibilityCandidates(
  mode: "surgeon" | "instrumentist",
  personId: number,
  q: string,
  page: number,
  limit = 20,
): Promise<CandidatePage> {
  const res = await apiClient.get("/api/incompatibilities/candidates", {
    params: { [mode === "surgeon" ? "surgeonId" : "instrumentistId"]: personId, q, page, limit },
  });
  return res.data as CandidatePage;
}

export async function createIncompatibility(surgeonId: number, instrumentistId: number): Promise<Incompatibility> {
  const res = await apiClient.post("/api/incompatibilities", { surgeonId, instrumentistId });
  return res.data as Incompatibility;
}

// ── Both ─────────────────────────────────────────────────────────────────────

export async function liftIncompatibility(id: number): Promise<Incompatibility> {
  const res = await apiClient.post(`/api/incompatibilities/${id}/lift`);
  return res.data as Incompatibility;
}

export function apiErrorMessage(err: unknown): string {
  const e = err as { response?: { data?: { error?: { message?: string } } }; message?: string };
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}
