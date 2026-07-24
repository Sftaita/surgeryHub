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

export async function patchMissionIntervention(
  missionId: number,
  interventionId: number,
  body: PatchInterventionBody,
): Promise<MissionInterventionDto> {
  const { data } = await apiClient.patch<MissionInterventionDto>(
    `/api/missions/${missionId}/interventions/${interventionId}`,
    body,
  );
  return data;
}

export async function deleteMissionIntervention(
  missionId: number,
  interventionId: number,
): Promise<void> {
  await apiClient.delete(
    `/api/missions/${missionId}/interventions/${interventionId}`,
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
): Promise<MaterialLineDto> {
  const { data } = await apiClient.patch<MaterialLineDto>(
    `/api/missions/${missionId}/material-lines/${lineId}`,
    body,
  );
  return data;
}

export async function deleteMissionMaterialLine(
  missionId: number,
  lineId: number,
): Promise<void> {
  await apiClient.delete(`/api/missions/${missionId}/material-lines/${lineId}`);
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
export async function fetchFirmServiceOfferings(
  firmId: number,
): Promise<Array<{ id: number; interventionType: CatalogInterventionType; active: boolean }>> {
  const { data } = await apiClient.get<Array<{ id: number; interventionType: CatalogInterventionType; active: boolean }>>(
    `/api/firms/${firmId}/service-offerings`,
  );
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

export async function rejectMissionEncoding(
  missionId: number,
  comment: string,
): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/encoding/reject`, { comment });
}

export async function reopenMissionEncoding(
  missionId: number,
  comment?: string,
): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/encoding/reopen`, { comment });
}
