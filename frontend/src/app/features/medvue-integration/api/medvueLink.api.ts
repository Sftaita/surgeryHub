import { apiClient } from "../../../api/apiClient";

/** D-140 — état de la liaison MedVue d'un compte (docs/api.md « Intégration MedVue »). */
export type MedVueLinkStatus = {
  configured: boolean;
  linked: boolean;
  linkedAt: string | null;
  linkedBy: { id: number; displayName: string } | null;
};

export function medvueLinkQueryKey(userId: number) {
  return ["medvue-link", userId] as const;
}

export async function fetchMedVueLink(userId: number): Promise<MedVueLinkStatus> {
  const { data } = await apiClient.get<MedVueLinkStatus>(`/api/medvue-integration/users/${userId}/link`);
  return data;
}

export async function linkMedVueAccount(userId: number, code: string): Promise<MedVueLinkStatus> {
  const { data } = await apiClient.post<MedVueLinkStatus>(`/api/medvue-integration/users/${userId}/link`, { code });
  return data;
}

export async function revokeMedVueLink(userId: number): Promise<void> {
  await apiClient.delete(`/api/medvue-integration/users/${userId}/link`);
}

/**
 * Message serveur affichable tel quel : le backend renvoie toujours un message en français,
 * sans détail technique (MedVueLinkException). Jamais de message construit côté client à
 * partir d'une hypothèse sur la cause.
 */
export function medvueLinkErrorMessage(err: unknown): string {
  const e = err as { response?: { data?: { error?: { message?: string } } } };
  return e?.response?.data?.error?.message ?? "Une erreur est survenue. Réessayez plus tard.";
}
