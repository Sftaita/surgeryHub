export type EncodingMaterialItem = {
  id: number;
  manufacturer: string;
  referenceCode: string;
  label: string;
  unit: string;
  isImplant: boolean;
};

export type EncodingMaterialLine = {
  id: number;
  item: EncodingMaterialItem;
  quantity: string; // backend: "1.00"
  comment: string; // backend: "Optionnel"
};

export type EncodingMaterialItemRequest = {
  id: number;
  label: string;
  referenceCode: string;
  comment: string; // backend: "Optionnel"
};

export type EncodingFirm = {
  id: number;
  firmName: string;
  materialLines: EncodingMaterialLine[];
  materialItemRequests: EncodingMaterialItemRequest[];
};

export type EncodingIntervention = {
  id: number;
  code: string;
  label: string;
  orderIndex: number;
  firms: EncodingFirm[];
};

export type MissionEncodingResponse = {
  missionId: number;
  missionType: "BLOCK" | "CONSULTATION" | string;
  missionStatus: string;

  interventions: EncodingIntervention[];
};

/**
 * DTO retourné par:
 * - POST /api/missions/{missionId}/interventions  (201)
 * - PATCH /api/missions/{missionId}/interventions/{interventionId} (200)
 */
export type MissionInterventionDto = {
  id: number;
  missionId: number;
  code: string;
  label: string;
  orderIndex: number;
  firms: any[]; // backend renvoie firms: [] ; l'encodage complet vient de GET encoding
};

export type CreateInterventionBody = {
  code: string;
  label: string;
  orderIndex: number;
};

export type PatchInterventionBody = Partial<CreateInterventionBody>;
