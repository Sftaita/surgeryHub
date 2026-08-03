import { apiClient } from "../../../api/apiClient";

export interface InterventionType {
  id: number;
  code: string;
  label: string;
  specialty: string | null;
  active: boolean;
  mergedIntoId?: number | null;
  /** Présent uniquement sur GET (liste) — nombre de firmes ayant une prestation sur ce type. */
  firmsCount?: number;
}

export type SimilarityConfidence = "HIGH" | "MEDIUM" | "LOW";

export interface SimilarInterventionTypeCandidate {
  type: InterventionType;
  confidence: SimilarityConfidence;
}

export interface InterventionTypeAuditRow {
  id: number;
  code: string;
  label: string;
  active: boolean;
  merged: boolean;
  mergedIntoId: number | null;
  firmsCount: number;
  firms: string[];
  missionsCount: number;
  pricingRulesCount: number;
  financialLinesCount: number;
  candidates: Array<{ id: number; code: string; label: string; confidence: SimilarityConfidence }>;
}

export interface InterventionTypeOfferingRow {
  offeringId: number;
  firm: { id: number; name: string; logoPath: string | null };
  active: boolean;
  feeApplicable: boolean;
  forfait: { amount: string; currency: string } | null;
}

export interface InterventionTypeMergeResult {
  source: InterventionType;
  target: InterventionType;
  offeringsReassigned: number;
  missionInterventionsReassigned: number;
  pricingRulesReassigned: number;
  pricingRulesSkipped: number[];
}

export async function getInterventionTypes(activeOnly = false): Promise<InterventionType[]> {
  const res = await apiClient.get("/api/intervention-types", {
    params: activeOnly ? { active: true } : undefined,
  });
  return res.data;
}

export async function createInterventionType(body: {
  code: string;
  label: string;
  specialty?: string;
}): Promise<InterventionType> {
  const res = await apiClient.post("/api/intervention-types", body);
  return res.data;
}

export async function updateInterventionType(
  id: number,
  body: { label?: string; specialty?: string | null; active?: boolean },
): Promise<InterventionType> {
  const res = await apiClient.patch(`/api/intervention-types/${id}`, body);
  return res.data;
}

export async function deleteInterventionType(id: number): Promise<void> {
  await apiClient.delete(`/api/intervention-types/${id}`);
}

/**
 * Task 11, section 6 — suggestion de rapprochement AVANT création (jamais un blocage).
 * Le manager reste toujours libre de créer quand même si les interventions sont réellement
 * différentes.
 */
export async function findSimilarInterventionTypes(
  label: string,
  excludeId?: number,
): Promise<SimilarInterventionTypeCandidate[]> {
  const res = await apiClient.get("/api/intervention-types/similar", {
    params: { label, excludeId },
  });
  return res.data;
}

/**
 * Catalogue > Prestations, refonte UX — détail d'une intervention globale : firmes
 * utilisatrices + forfait résolu (PricingRuleResolver, jamais recalculé côté frontend).
 */
export async function getInterventionTypeOfferings(id: number): Promise<InterventionTypeOfferingRow[]> {
  const res = await apiClient.get(`/api/intervention-types/${id}/offerings`);
  return res.data;
}

/** Task 11, section 3 — rapport d'audit complet, lecture seule. */
export async function getInterventionTypeDuplicateAudit(): Promise<InterventionTypeAuditRow[]> {
  const res = await apiClient.get("/api/intervention-types/duplicate-audit");
  return res.data;
}

/** Task 11, section 7 — fusion explicite, jamais automatique. */
export async function mergeInterventionType(sourceId: number, targetId: number): Promise<InterventionTypeMergeResult> {
  const res = await apiClient.post(`/api/intervention-types/${sourceId}/merge`, { targetId });
  return res.data;
}
