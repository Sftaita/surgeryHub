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
 * FinancialCalculationService (via InstrumentistRateResolver). Distincte des champs legacy
 * User.hourlyRate/consultationFee (encore utilisés par l'ancien flux de génération de décompte).
 * Sans tarif HOURLY_RATE actif ici, "Calculer la valorisation" échoue sur la fiche mission.
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
): Promise<InstrumentistRate> {
  const res = await apiClient.post(`/api/instrumentists/${userId}/rates/${rateId}/replace`, body);
  return res.data;
}
