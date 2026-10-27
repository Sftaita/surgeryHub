import { apiClient } from "../../../api/apiClient";
import type {
  MissionEncodingResponse,
  CreateInterventionBody,
  PatchInterventionBody,
  MissionInterventionDto,
  CreateMaterialLineBody,
  PatchMaterialLineBody,
  MaterialLineDto,
  CreateMaterialItemRequestBody,
  MaterialItemRequestDto,
  CreateInterventionTypeRequestBody,
  CreateInterventionTypeRequestResponse,
  InterventionTypeEncodingContext,
  CatalogInterventionType,
  ChoiceGroupSummary,
} from "./encoding.types";

export async function fetchMissionEncoding(
  missionId: number,
): Promise<MissionEncodingResponse> {
  const { data } = await apiClient.get<MissionEncodingResponse>(
    `/api/missions/${missionId}/encoding`,
  );
  return data;
}

/**
 * Lot 6 — contexte riche pour l'encodage : prestations, firme principale suggérée,
 * matériels suggérés, en un seul appel quand l'instrumentiste sélectionne un type.
 * GET /api/intervention-types/{id}/encoding-context
 */
export async function fetchInterventionTypeEncodingContext(
  interventionTypeId: number,
): Promise<InterventionTypeEncodingContext> {
  const { data } = await apiClient.get<InterventionTypeEncodingContext>(
    `/api/intervention-types/${interventionTypeId}/encoding-context`,
  );
  return data;
}

/**
 * Interventions CRUD
 * - POST   /api/missions/{missionId}/interventions
 * - PATCH  /api/missions/{missionId}/interventions/{interventionId}
 * - DELETE /api/missions/{missionId}/interventions/{interventionId}
 */
export async function createMissionIntervention(
  missionId: number,
  body: CreateInterventionBody,
): Promise<MissionInterventionDto> {
  const { data } = await apiClient.post<MissionInterventionDto>(
    `/api/missions/${missionId}/interventions`,
    body,
  );
  return data;
}

/**
 * D-154 — protection contre les écrasements concurrents (instrumentiste, chirurgien,
 * manager sur la même mission) : la version lue est renvoyée en `If-Match` ; le backend
 * répond 409 ENCODING_CONCURRENT_MODIFICATION si l'élément a changé entre-temps.
 */
function ifMatch(expectedVersion?: number | null): [] | [{ headers: Record<string, string> }] {
  return expectedVersion != null ? [{ headers: { "If-Match": `"${expectedVersion}"` } }] : [];
}

export const ENCODING_CONCURRENT_MODIFICATION = "ENCODING_CONCURRENT_MODIFICATION";

export function isEncodingConcurrentModification(err: unknown): boolean {
  return (err as { response?: { data?: { error?: { code?: string } } } })?.response?.data?.error?.code === ENCODING_CONCURRENT_MODIFICATION;
}

export async function patchMissionIntervention(
  missionId: number,
  interventionId: number,
  body: PatchInterventionBody,
  expectedVersion?: number | null,
): Promise<MissionInterventionDto> {
  const { data } = await apiClient.patch<MissionInterventionDto>(
    `/api/missions/${missionId}/interventions/${interventionId}`,
    body,
    ...ifMatch(expectedVersion),
  );
  return data;
}

export async function deleteMissionIntervention(
  missionId: number,
  interventionId: number,
  expectedVersion?: number | null,
): Promise<void> {
  await apiClient.delete(
    `/api/missions/${missionId}/interventions/${interventionId}`,
    ...ifMatch(expectedVersion),
  );
}

/**
 * Material lines CRUD
 * - POST   /api/missions/{missionId}/material-lines
 * - PATCH  /api/missions/{missionId}/material-lines/{lineId}
 * - DELETE /api/missions/{missionId}/material-lines/{lineId}
 */
export async function createMissionMaterialLine(
  missionId: number,
  body: CreateMaterialLineBody,
): Promise<MaterialLineDto> {
  const { data } = await apiClient.post<MaterialLineDto>(
    `/api/missions/${missionId}/material-lines`,
    body,
  );
  return data;
}

export async function patchMissionMaterialLine(
  missionId: number,
  lineId: number,
  body: PatchMaterialLineBody,
  expectedVersion?: number | null,
): Promise<MaterialLineDto> {
  const { data } = await apiClient.patch<MaterialLineDto>(
    `/api/missions/${missionId}/material-lines/${lineId}`,
    body,
    ...ifMatch(expectedVersion),
  );
  return data;
}

export async function deleteMissionMaterialLine(
  missionId: number,
  lineId: number,
  expectedVersion?: number | null,
): Promise<void> {
  await apiClient.delete(`/api/missions/${missionId}/material-lines/${lineId}`, ...ifMatch(expectedVersion));
}

/**
 * Material item requests
 * - POST /api/missions/{missionId}/material-item-requests
 */
export async function createMissionMaterialItemRequest(
  missionId: number,
  body: CreateMaterialItemRequestBody,
): Promise<MaterialItemRequestDto> {
  const { data } = await apiClient.post<MaterialItemRequestDto>(
    `/api/missions/${missionId}/material-item-requests`,
    body,
  );
  return data;
}

/**
 * "Demande de nouveau type" (Lot 5, D-068)
 * - POST /api/missions/{missionId}/intervention-type-requests
 */
export async function createMissionInterventionTypeRequest(
  missionId: number,
  body: CreateInterventionTypeRequestBody,
): Promise<CreateInterventionTypeRequestResponse> {
  const { data } = await apiClient.post<CreateInterventionTypeRequestResponse>(
    `/api/missions/${missionId}/intervention-type-requests`,
    body,
  );
  return data;
}

/**
 * EPIC Revue instrumentiste, Lot 3, commit 8 — parcours "firme puis intervention" : une
 * fois la firme choisie, on ne propose que les types d'intervention pour lesquels une
 * prestation existe chez cette firme (accélérateur de saisie, jamais une restriction
 * dure côté backend — voir FirmServiceOfferingController, "jamais lu par le moteur
 * financier"). GET /api/firms/{firmId}/service-offerings
 */
export type FirmServiceOfferingSummary = {
  id: number;
  interventionType: CatalogInterventionType;
  active: boolean;
  /** Refonte Catalogue/Prestations (D-092) — voir AddInterventionDialog/EditInterventionDialog. */
  representativePresenceRelevant: boolean;
  /**
   * Tarification firme conditionnée à un choix obligatoire — présent (non null) et
   * "opérationnel" seulement (≥2 options actives) : voir AddInterventionDialog/
   * EditInterventionDialog. Jamais un montant.
   */
  choiceGroup: ChoiceGroupSummary | null;
};

export async function fetchFirmServiceOfferings(firmId: number): Promise<FirmServiceOfferingSummary[]> {
  const { data } = await apiClient.get<FirmServiceOfferingSummary[]>(`/api/firms/${firmId}/service-offerings`);
  return data;
}

/**
 * Cycle de vie de l'encodage (Lot 7, D-070).
 * `start` n'a plus de point d'entrée frontend (revue UX instrumentiste, lot 1) : c'était
 * une invite optionnelle vers ENCODING_IN_PROGRESS, jamais un préalable requis — l'API
 * `POST /api/missions/{id}/encoding/start` reste disponible côté backend (contrat inchangé).
 * `complete` n'est pas ici : "Terminer l'encodage" utilise toujours l'alias legacy
 * `POST /api/missions/{id}/submit` (submitMission dans missions.api.ts) — le backend
 * délègue au même MissionEncodingWorkflowService::complete(), jamais deux implémentations.
 */
export async function validateMissionEncoding(missionId: number): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/encoding/validate`);
}

/**
 * D-171 — « Terminer et valider » (manager) : clôt puis valide un encodage encore ouvert.
 * `expectedRevision` = `encodingRevision` du détail de mission (409 si l'encodage a changé
 * entre-temps) ; `takeoverConfirmed` = reprise confirmée (exigée quand une instrumentiste est affectée).
 */
export async function completeAndValidateMissionEncoding(
  missionId: number,
  body: { comment: string | null; expectedRevision: string | null; takeoverConfirmed: boolean },
): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/encoding/complete-and-validate`, {
    ...(body.comment !== null ? { comment: body.comment } : {}),
    expectedRevision: body.expectedRevision,
    takeoverConfirmed: body.takeoverConfirmed,
  });
}

export async function rejectMissionEncoding(
  missionId: number,
  comment: string,
): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/encoding/reject`, { comment });
}

/** D-148 — motif de la correction obligatoire (refusé en 422 par le backend sinon). */
export async function reopenMissionEncoding(
  missionId: number,
  comment: string,
): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/encoding/reopen`, { comment });
}

/**
 * D-120 — relance manuelle (cockpit Suivi des encodages). Ne mute aucun statut.
 * H5a — `batchId` : une relance = un lot ; une requête rejouée avec le même lot n'envoie
 * jamais une seconde relance (l'email de cette action explicite est obligatoire).
 * D-143 — renvoie le détail mission à jour (lastManualReminderChannel : canaux réellement
 * utilisés, 'push+email' | 'push' | 'email' | 'none') ; 409 si une relance date de < 10 min.
 */
export async function remindMissionEncoding(missionId: number, batchId?: string): Promise<{ lastManualReminderChannel?: string | null }> {
  const { data } = await apiClient.post(`/api/missions/${missionId}/encoding/remind`, batchId ? { batchId } : {});
  return data ?? {};
}

/** H5a — identifiant d'un lot d'action manager (idempotence côté serveur). */
export function newActionBatchId(): string {
  return typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
    ? crypto.randomUUID()
    : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}
