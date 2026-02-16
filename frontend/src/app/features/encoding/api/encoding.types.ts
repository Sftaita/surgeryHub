/**
 * Lot 4 (nouveau modèle) :
 * Intervention -> Material lines -> Item -> Firm
 * Firm est un référentiel (Manager/Admin) et n'est jamais créé côté instrumentiste.
 */

export type CatalogFirm = {
  id: number;
  name: string;
  active: boolean;
};

export type CatalogItem = {
  id: number;
  label: string;
  referenceCode: string;
  unit: string;
  active: boolean;
  isImplant: boolean;
  firm: {
    id: number;
    name: string;
  };
};

export type EncodingMaterialItem = {
  id: number;
  label: string;
  referenceCode: string;
  unit: string;
  isImplant: boolean;
  firm: {
    id: number;
    name: string;
  };
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

export type EncodingIntervention = {
  id: number;
  code: string;
  label: string;
  orderIndex: number;
  materialLines: EncodingMaterialLine[];
  materialItemRequests?: EncodingMaterialItemRequest[];
};

export type MissionEncodingResponse = {
  mission: {
    id: number;
    type: "BLOCK" | "CONSULTATION" | string;
    status: string;
    allowedActions: string[];
  };
  interventions: EncodingIntervention[];
  catalog?: {
    items: CatalogItem[];
    firms: CatalogFirm[];
  };
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
  // le détail (materialLines) vient de GET /api/missions/{id}/encoding
};

export type CreateInterventionBody = {
  code: string;
  label: string;
  orderIndex: number;
};

export type PatchInterventionBody = Partial<CreateInterventionBody>;
