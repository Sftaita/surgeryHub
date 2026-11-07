import { apiClient } from "../../../api/apiClient";

/**
 * D-147 — journal d'activité manager (GET /api/manager/audit-events) et historique des
 * demandes chirurgien (GET /api/manager/surgeon-mission-requests/history) — même forme
 * de réponse (AuditJournalService::serialize()).
 */
export interface AuditPersonRef {
  id: number | null;
  displayName: string;
}

export interface AuditJournalEntry {
  id: number;
  eventType: string;
  category: string;
  occurredAt: string; // ISO
  actor: AuditPersonRef | null;
  mission: {
    id: number;
    status: string;
    startAt: string | null;
    endAt: string | null;
    site: { id: number; name: string } | null;
    surgeon: AuditPersonRef | null;
    instrumentist: AuditPersonRef | null;
  } | null;
  surgeonMissionRequest: {
    id: number;
    status: "PENDING" | "ACCEPTED" | "REJECTED";
    startAt: string | null;
    endAt: string | null;
    site: { id: number; name: string } | null;
    surgeon: AuditPersonRef | null;
  } | null;
  payload: Record<string, unknown>;
}

export interface AuditJournalPage {
  items: AuditJournalEntry[];
  total: number;
  page: number;
  limit: number;
}

export interface AuditJournalFilters {
  category?: string;
  q?: string;
  from?: string; // ISO, inclus
  to?: string; // ISO, exclu
  missionId?: number;
}

export async function fetchAuditJournal(filters: AuditJournalFilters, page = 1, limit = 50): Promise<AuditJournalPage> {
  const { data } = await apiClient.get<AuditJournalPage>("/api/manager/audit-events", {
    params: { ...filters, page, limit },
  });
  return data;
}

export async function fetchSurgeonMissionRequestHistory(page = 1, limit = 50): Promise<AuditJournalPage> {
  const { data } = await apiClient.get<AuditJournalPage>("/api/manager/surgeon-mission-requests/history", {
    params: { page, limit },
  });
  return data;
}

/** Page suivante pour useInfiniteQuery — undefined quand tout est chargé. */
export function nextAuditPage(last: AuditJournalPage): number | undefined {
  return last.page * last.limit < last.total ? last.page + 1 : undefined;
}
