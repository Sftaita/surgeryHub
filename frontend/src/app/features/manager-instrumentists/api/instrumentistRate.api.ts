import { apiClient } from "../../../api/apiClient";

export type InstrumentistRateType = "HOURLY_RATE" | "CONSULTATION_FEE";

export interface InstrumentistRate {
  id: number;
  instrumentist: { id: number };
  rateType: InstrumentistRateType;
  amount: string;
  currency: string;
  validFrom: string | null;
  validTo: string | null;
}

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — source de tarif utilisée exclusivement par
 * FinancialCalculationService (via InstrumentistRateResolver), à la date effective de chaque
 * mission. Seule source de tarif affichée au manager (D-172) ; sans tarif HOURLY_RATE applicable,
 * "Calculer la valorisation" échoue sur la fiche mission.
 */
export async function getInstrumentistRates(userId: number, rateType?: InstrumentistRateType): Promise<InstrumentistRate[]> {
  const res = await apiClient.get(`/api/instrumentists/${userId}/rates`, { params: rateType ? { rateType } : undefined });
  return res.data;
}

export async function createInstrumentistRate(
  userId: number,
  body: { rateType: InstrumentistRateType; amount: number; currency?: string; validFrom?: string | null; validTo?: string | null }
): Promise<InstrumentistRate> {
  const res = await apiClient.post(`/api/instrumentists/${userId}/rates`, body);
  return res.data;
}

export async function updateInstrumentistRate(
  userId: number,
  rateId: number,
  body: { amount?: number; currency?: string; validFrom?: string | null; validTo?: string | null }
): Promise<InstrumentistRate> {
  const res = await apiClient.patch(`/api/instrumentists/${userId}/rates/${rateId}`, body);
  return res.data;
}

export async function deleteInstrumentistRate(userId: number, rateId: number): Promise<void> {
  await apiClient.delete(`/api/instrumentists/${userId}/rates/${rateId}`);
}

export async function replaceInstrumentistRate(
  userId: number,
  rateId: number,
  body: { amount: number; currency?: string; effectiveFrom: string }
): Promise<InstrumentistRate & { staleCalculations?: RateImpactCalculation[] }> {
  const res = await apiClient.post(`/api/instrumentists/${userId}/rates/${rateId}/replace`, body);
  return res.data;
}

/** D-172 — calcul financier concerné par un changement de période tarifaire. */
export interface RateImpactCalculation {
  calculationId: number;
  missionId: number;
  missionDate: string;
  status: string;
}

export interface RateEffectiveDateImpact {
  dryRun: boolean;
  rate: InstrumentistRate;
  previousRate: InstrumentistRate | null;
  oldValidFrom: string;
  newValidFrom: string;
  period: { from: string; to: string };
  /** Calculs verrouillés (facture ou relevé émis) : le changement est refusé tant qu'il y en a. */
  lockedCalculations: RateImpactCalculation[];
  /** Calculs non verrouillés à recalculer après le changement (jamais recalculés automatiquement). */
  staleCalculations: RateImpactCalculation[];
}

/** D-172 — date de prise d'effet, rétroactive comprise ; `dryRun` renvoie l'impact sans rien écrire. */
export async function changeInstrumentistRateEffectiveDate(
  userId: number,
  rateId: number,
  body: { validFrom: string; dryRun?: boolean }
): Promise<RateEffectiveDateImpact> {
  const res = await apiClient.post(`/api/instrumentists/${userId}/rates/${rateId}/effective-date`, body);
  return res.data;
}
