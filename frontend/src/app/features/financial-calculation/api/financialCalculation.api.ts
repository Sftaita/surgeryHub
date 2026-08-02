import { apiClient } from "../../../api/apiClient";

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — le calcul financier déterministe
 * d'une mission : fige les tarifs/montants au moment du calcul (effectiveAt), jamais
 * recalculé en place. Une nouvelle valorisation crée une nouvelle version
 * (recalculate()) — l'ancienne passe SUPERSEDED, jamais supprimée. C'est CE calcul,
 * une fois APPROVED/LOCKED, qui alimente les lignes disponibles pour la facturation
 * (Lot 4) et les statistiques financières (Lot 7) — sans lui, une mission "validée"
 * ne génère jamais aucune valeur générée mesurable.
 */
export type FinancialCalculationStatus = "CALCULATED" | "APPROVED" | "LOCKED" | "SUPERSEDED" | "CANCELLED";

/** Refonte Catalogue/Prestations (D-092) — voir FinancialCalculationLine (backend). */
export interface FinancialCalculationLineWarning {
  code: string;
  message: string;
}

export interface FinancialCalculationLineDetail {
  id: number;
  beneficiaryType: "FIRM" | "INSTRUMENTIST";
  beneficiaryFirmId: number | null;
  beneficiaryInstrumentistId: number | null;
  lineType: "FIRM_INTERVENTION_FEE" | "FIRM_MATERIAL_FEE" | "INSTRUMENTIST_HOURLY" | "INSTRUMENTIST_CONSULTATION_FEE";
  sourceType: string;
  descriptionSnapshot: string;
  quantity: string;
  durationMinutes: number | null;
  unitAmount: string;
  /** Refonte Catalogue/Prestations (D-092) — montant avant toute politique délégué, toujours renseigné. */
  grossAmount: string;
  /** totalAmount - grossAmount ; "0.00" = aucun ajustement. */
  adjustmentAmount: string;
  totalAmount: string;
  currency: string;
  effectiveAt: string | null;
  snapshot: Record<string, unknown> & {
    adjustmentReasonSnapshot?: string | null;
    representativePresentSnapshot?: boolean | null;
    representativePolicySnapshot?: {
      representativePresenceRelevant: boolean;
      representativeSuppressesInterventionFee: boolean;
      representativeSuppressesOwnMaterialFees: boolean;
      feeApplicable: boolean;
    } | null;
  };
  warnings: FinancialCalculationLineWarning[];
}

export interface FinancialCalculation {
  id: number;
  missionId: number;
  version: number;
  status: FinancialCalculationStatus;
  effectiveAt: string | null;
  currencyPolicy: string;
  calculatedAt: string | null;
  calculatedBy: number | null;
  approvedAt: string | null;
  approvedBy: number | null;
  lockedAt: string | null;
  cancelledAt: string | null;
  cancelledBy: number | null;
  supersededAt: string | null;
  supersededByCalculationId: number | null;
  totalsByCurrency: Record<string, Record<string, string>>;
  lines: FinancialCalculationLineDetail[];
}

export async function listMissionCalculations(missionId: number): Promise<FinancialCalculation[]> {
  const res = await apiClient.get(`/api/missions/${missionId}/financial-calculations`);
  return res.data;
}

export async function calculateMission(missionId: number): Promise<FinancialCalculation> {
  const res = await apiClient.post(`/api/missions/${missionId}/financial-calculations`);
  return res.data;
}

export async function getFinancialCalculation(id: number): Promise<FinancialCalculation> {
  const res = await apiClient.get(`/api/financial-calculations/${id}`);
  return res.data;
}

export async function recalculateFinancialCalculation(id: number): Promise<FinancialCalculation> {
  const res = await apiClient.post(`/api/financial-calculations/${id}/recalculate`);
  return res.data;
}

export async function approveFinancialCalculation(id: number): Promise<FinancialCalculation> {
  const res = await apiClient.post(`/api/financial-calculations/${id}/approve`);
  return res.data;
}

export async function lockFinancialCalculation(id: number): Promise<FinancialCalculation> {
  const res = await apiClient.post(`/api/financial-calculations/${id}/lock`);
  return res.data;
}
