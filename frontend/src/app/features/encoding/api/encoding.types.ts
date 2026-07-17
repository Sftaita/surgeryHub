/**
 * Lot 4 (nouveau modèle) :
 * Intervention -> Material lines -> Item -> Firm
 * Firm est un référentiel (Manager/Admin) et n'est jamais créé côté instrumentiste.
 */

export type CatalogFirm = {
  id: number;
  name: string;
  active?: boolean;
};

export type CatalogItem = {
  id: number;
  label: string;
  referenceCode: string;
  unit: string;
  active?: boolean;
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
  missionInterventionId: number;
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

/** Référentiel fermé (Lot 1) — voir intervention-types feature. */
export type CatalogInterventionType = {
  id: number;
  code: string;
  label: string;
};

/**
 * Lot 5 (D-068) : `code`/`label` restent l'instantané figé à la création (jamais relu
 * depuis le référentiel) — inchangés même si `interventionType` est ensuite renommé ou
 * désactivé ailleurs. `interventionType` est `null` uniquement pour les lignes
 * historiques antérieures au Lot 5 (non mappées, ex: mission #529).
 */
export type EncodingIntervention = {
  id: number;
  code: string;
  label: string;
  orderIndex: number;
  interventionType: CatalogInterventionType | null;
  primaryFirm: { id: number; name: string } | null;
  materialLines: EncodingMaterialLine[];
  materialItemRequests?: EncodingMaterialItemRequest[];
};

/** "Demande de nouveau type" (Lot 5, D-068) — pas rattachée à une intervention : elle
 *  précède toujours sa création (aucune intervention ne peut exister sans type valide). */
export type EncodingInterventionTypeRequest = {
  id: number;
  label: string;
  suggestedCode: string | null;
  comment: string | null;
};

export type MissionEncodingResponse = {
  mission: {
    id: number;
    type: "BLOCK" | "CONSULTATION" | string;
    status: string;
    allowedActions: string[];
  };
  interventions: EncodingIntervention[];
  interventionTypeRequests: EncodingInterventionTypeRequest[];
  catalog?: {
    items: CatalogItem[];
    firms: CatalogFirm[];
    interventionTypes: CatalogInterventionType[];
  };
};

/**
 * DTO retourné par:
 * - POST /api/missions/{missionId}/interventions  (201, {id} seulement)
 * - PATCH /api/missions/{missionId}/interventions/{interventionId} (204, pas de corps)
 */
export type MissionInterventionDto = {
  id: number;
};

/**
 * Lot 5 (D-068) : `interventionTypeId` obligatoire (référentiel fermé) remplace
 * l'ancien `code`/`label` texte libre — le nom/code affichés sont dérivés côté serveur.
 * `primaryFirmId` reste facultatif.
 */
export type CreateInterventionBody = {
  interventionTypeId: number;
  primaryFirmId?: number;
  orderIndex: number;
};

/**
 * `primaryFirmId` supporte le retrait explicite : omettre la clé = ne pas toucher à la
 * firme actuelle ; `primaryFirmId: null` = la retirer.
 */
export type PatchInterventionBody = {
  interventionTypeId?: number;
  primaryFirmId?: number | null;
  orderIndex?: number;
};

/**
 * "Demande de nouveau type" (Lot 5, D-068)
 * - POST /api/missions/{missionId}/intervention-type-requests
 */
export type CreateInterventionTypeRequestBody = {
  label: string;
  suggestedCode?: string;
  comment?: string;
};

/**
 * Material lines
 * - POST   /api/missions/{missionId}/material-lines
 * - PATCH  /api/missions/{missionId}/material-lines/{lineId}
 * - DELETE /api/missions/{missionId}/material-lines/{lineId}
 */
export type CreateMaterialLineBody = {
  missionInterventionId: number;
  itemId: number;
  quantity: string; // IMPORTANT: string (decimal doctrine)
  comment?: string;
};

export type PatchMaterialLineBody = {
  quantity?: string; // IMPORTANT: string
  comment?: string;
};

export type MaterialLineDto = {
  id: number;
  missionInterventionId: number;
  item: EncodingMaterialItem;
  quantity: string;
  comment: string;
};

export type CreateMaterialItemRequestBody = {
  missionInterventionId: number;
  label: string;
  referenceCode?: string;
  comment?: string;
};

export type MaterialItemRequestDto = {
  id: number;
  label: string;
  referenceCode: string | null;
  comment: string | null;
  status: "PENDING" | "RESOLVED" | "IGNORED";
};
