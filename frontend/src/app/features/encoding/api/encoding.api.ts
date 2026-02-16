import { apiClient } from "../../../api/apiClient";
import type {
  MissionEncodingResponse,
  CreateInterventionBody,
  PatchInterventionBody,
  MissionInterventionDto,
  CreateMaterialLineBody,
  PatchMaterialLineBody,
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
    {
      code: body.code,
      label: body.label,
      orderIndex: body.orderIndex,
    },
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
 * Material lines CRUD (Lot 4)
 * - POST   /api/missions/{missionId}/material-lines
 * - PATCH  /api/missions/{missionId}/material-lines/{lineId}
 * - DELETE /api/missions/{missionId}/material-lines/{lineId}
 *
 * ⚠️ Le frontend n'envoie jamais firmId : uniquement itemId (+ interventionId).
 */
export async function createMissionMaterialLine(
  missionId: number,
  body: CreateMaterialLineBody,
): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/material-lines`, {
    interventionId: body.interventionId,
    itemId: body.itemId,
    quantity: body.quantity,
    comment: body.comment ?? "",
  });
}

export async function patchMissionMaterialLine(
  missionId: number,
  lineId: number,
  body: PatchMaterialLineBody,
): Promise<void> {
  await apiClient.patch(`/api/missions/${missionId}/material-lines/${lineId}`, {
    ...(body.quantity !== undefined ? { quantity: body.quantity } : {}),
    ...(body.comment !== undefined ? { comment: body.comment } : {}),
  });
}

export async function deleteMissionMaterialLine(
  missionId: number,
  lineId: number,
): Promise<void> {
  await apiClient.delete(`/api/missions/${missionId}/material-lines/${lineId}`);
}
