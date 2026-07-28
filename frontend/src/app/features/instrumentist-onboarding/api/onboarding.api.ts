import { apiClient } from "../../../api/apiClient";
import type { MeResponse } from "../../me/api/me.api";

/**
 * POST /api/me/onboarding/complete — idempotent, réservé au rôle INSTRUMENTIST.
 * Retourne le MeResponse à jour (instrumentistOnboardingCompleted: true).
 */
export async function completeInstrumentistOnboarding(): Promise<MeResponse> {
  const res = await apiClient.post<MeResponse>("/api/me/onboarding/complete");
  return res.data;
}
