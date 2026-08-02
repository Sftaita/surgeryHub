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

/**
 * EPIC Revue instrumentiste, Lot 3, commit 8 — `missionInterventionId`/
 * `interventionDraftId` sont mutuellement exclusifs (jamais les deux renseignés à la
 * fois) : une ligne appartient soit à une intervention réelle, soit à un draft.
 */
export type EncodingMaterialLine = {
  id: number;
  missionInterventionId: number | null;
  interventionDraftId?: number | null;
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
/**
 * Lot 6 — signaux de cohérence, informationnels uniquement (jamais bloquants). L'UX des
 * avertissements viendra plus tard ; ces champs existent pour que le backend puisse déjà
 * les calculer et les exposer.
 */
export type MissionInterventionCoherence = {
  hasNoMaterialLines: boolean;
  unusedSuggestedMaterialItemIds: number[];
  unexpectedMaterialItemIds: number[];
  materialLineIdsFromOtherFirm: number[];
};

export type EncodingIntervention = {
  id: number;
  code: string;
  label: string;
  orderIndex: number;
  interventionType: CatalogInterventionType | null;
  primaryFirm: { id: number; name: string } | null;
  materialLines: EncodingMaterialLine[];
  materialItemRequests?: EncodingMaterialItemRequest[];
  /** Matériels suggérés par la prestation (firme principale × type) — Lot 6. Vide si
   *  primaryFirm n'est pas défini ou si aucune prestation ne couvre ce couple. */
  suggestedMaterials: CatalogItem[];
  coherence: MissionInterventionCoherence;
  /**
   * Refonte Catalogue/Prestations (D-092) — donnée factuelle, jamais financière. null =
   * jamais répondu (legacy, ou question non pertinente pour cette prestation). Optionnel
   * (et non `| undefined` sur le champ lui-même) pour rester compatible avec les fixtures
   * de test existantes qui ne le fournissent pas encore.
   */
  representativePresent?: boolean | null;
};

/** "Demande de nouveau type" (Lot 5, D-068) — pas rattachée à une intervention : elle
 *  précède toujours sa création (aucune intervention ne peut exister sans type valide). */
export type EncodingInterventionTypeRequest = {
  id: number;
  label: string;
  suggestedCode: string | null;
  comment: string | null;
};

/**
 * EPIC Revue instrumentiste, Lot 3, commit 7 (backend) / commit 8 (frontend) — liste de
 * lecture unifiée de l'écran d'encodage : MissionIntervention réelles (kind=INTERVENTION)
 * et MissionInterventionDraft encore utiles (kind=DRAFT, statut OPEN ou KEPT_AS_HISTORY —
 * CONVERTED/MATERIAL_REASSIGNED n'apparaissent jamais, le matériel est déjà représenté
 * sous la vraie intervention). Union discriminée par `kind` pour un narrowing propre côté
 * consommateurs, sans cast dispersé.
 */
export type EncodingEntryMaterialLine = EncodingMaterialLine;
export type EncodingEntryMaterialItemRequest = EncodingMaterialItemRequest;

export type MissionEncodingInterventionEntry = {
  kind: "INTERVENTION";
  id: number;
  requestId: null;
  orderIndex: number;
  label: string;
  interventionType: CatalogInterventionType | null;
  firm: { id: number; name: string } | null;
  requestedFirmNameSnapshot: null;
  /** Toujours "CATALOGUED" pour une intervention réelle. */
  status: string;
  /** Toujours false pour une intervention réelle. */
  readOnly: boolean;
  materialLines: EncodingEntryMaterialLine[];
  materialItemRequests: EncodingEntryMaterialItemRequest[];
  /** Refonte Catalogue/Prestations (D-092) — donnée factuelle, jamais financière. */
  representativePresent?: boolean | null;
};

export type MissionEncodingDraftEntry = {
  kind: "DRAFT";
  id: number;
  requestId: number;
  orderIndex: number;
  /** Libellé instantané figé à la création de la demande — jamais relu du référentiel. */
  label: string;
  interventionType: null;
  /** Firme demandée par l'instrumentiste — peut devenir null si la firme est ensuite
   *  supprimée/renommée ; voir requestedFirmNameSnapshot pour le nom figé à la création. */
  firm: { id: number; name: string } | null;
  requestedFirmNameSnapshot: string | null;
  /** "OPEN" (modifiable) ou "KEPT_AS_HISTORY" (lecture seule, voir readOnly). */
  status: "OPEN" | "KEPT_AS_HISTORY" | string;
  readOnly: boolean;
  materialLines: EncodingEntryMaterialLine[];
  materialItemRequests: EncodingEntryMaterialItemRequest[];
};

export type MissionEncodingEntry = MissionEncodingInterventionEntry | MissionEncodingDraftEntry;

/**
 * Lot 6 — réponse riche de GET /api/intervention-types/{id}/encoding-context. Toute
 * l'intelligence (firme suggérée, matériels suggérés par prestation) est calculée
 * côté backend ; le frontend n'a qu'à afficher/sélectionner.
 */
export type FirmServiceOfferingEncodingContext = {
  offeringId: number;
  firm: CatalogFirm;
  label: string | null;
  suggestedMaterials: CatalogItem[];
  /**
   * Refonte Catalogue/Prestations (D-092) — n'affiche la question "délégué présent ?" à
   * l'encodage que si ce champ est vrai pour la firme effectivement choisie. Jamais un
   * montant, jamais une politique de calcul.
   */
  representativePresenceRelevant: boolean;
};

export type InterventionTypeEncodingContext = {
  interventionType: CatalogInterventionType;
  /** Non nul seulement si une seule prestation active existe pour ce type (non ambigu). */
  suggestedPrimaryFirm: CatalogFirm | null;
  offerings: FirmServiceOfferingEncodingContext[];
};

/**
 * Lot 7 (D-070) — commentaire manager historisé (reject/reopen), jamais perdu ni
 * écrasé : une entrée par action. Pas de champ distinguant reject/reopen, l'API ne
 * l'expose pas — affiché comme un historique chronologique plat.
 */
export type EncodingComment = {
  id: number;
  comment: string;
  authorDisplayName: string;
  createdAt: string;
};

/**
 * Lot 7 (D-070) — agrégation mission-level des signaux de cohérence (Lot 6).
 * Informationnel uniquement, jamais bloquant : aide le manager à décider, ne
 * conditionne aucune règle serveur.
 */
export type MissionEncodingCoherenceSummary = {
  hasNoInterventions: boolean;
  hasInterventionsWithNoMaterial: boolean;
  hasUnusedSuggestions: boolean;
  hasMaterialFromOtherFirm: boolean;
  hasMissingPrimaryFirm: boolean;
};

export type MissionEncodingResponse = {
  mission: {
    id: number;
    type: "BLOCK" | "CONSULTATION" | string;
    status: string;
    allowedActions: string[];
  };
  /**
   * TRANSITOIRE — conservé pour compatibilité (ne contient que les MissionIntervention
   * réelles, jamais les drafts). Ne plus utiliser comme source principale de rendu :
   * préférer `entries`, déjà trié par orderIndex et incluant les drafts pertinents. Reste
   * lu ici uniquement pour l'enrichissement Lot 6 (suggestedMaterials/coherence) tant que
   * ces champs n'existent pas encore sur `entries` (hors périmètre du commit 7 backend).
   */
  interventions: EncodingIntervention[];
  /** Liste unifiée triée par orderIndex — source de vérité pour le rendu de cet écran. */
  entries: MissionEncodingEntry[];
  interventionTypeRequests: EncodingInterventionTypeRequest[];
  catalog?: {
    items: CatalogItem[];
    firms: CatalogFirm[];
    interventionTypes: CatalogInterventionType[];
  };
  coherenceSummary: MissionEncodingCoherenceSummary;
  encodingComments: EncodingComment[];
};

/**
 * DTO retourné par:
 * - POST /api/missions/{missionId}/interventions  (201, {id} seulement)
 * - PATCH /api/missions/{missionId}/interventions/{interventionId} (204, pas de corps)
 */
export type MissionInterventionDto = {
  id: number;
  orderIndex?: number;
  /** Refonte Catalogue/Prestations (D-092) — présent uniquement sur la réponse de création. */
  representativePresent?: boolean | null;
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
  /** Refonte Catalogue/Prestations (D-092) — facultatif, uniquement si déjà connu à la création. */
  representativePresent?: boolean;
};

/**
 * `primaryFirmId` supporte le retrait explicite : omettre la clé = ne pas toucher à la
 * firme actuelle ; `primaryFirmId: null` = la retirer. Même tri-état pour
 * `representativePresent` (D-092).
 */
export type PatchInterventionBody = {
  interventionTypeId?: number;
  primaryFirmId?: number | null;
  orderIndex?: number;
  representativePresent?: boolean | null;
};

/**
 * "Demande de nouveau type" (Lot 5, D-068) — `requestedFirmId` (Lot 3, commit 8) permet
 * de rattacher une firme demandée dès la création, comme pour une intervention réelle.
 * POST /api/missions/{missionId}/intervention-type-requests
 */
export type CreateInterventionTypeRequestBody = {
  label: string;
  suggestedCode?: string;
  comment?: string;
  requestedFirmId?: number;
};

/**
 * EPIC Revue instrumentiste, Lot 3, commit 8 — réponse enrichie de manière additive
 * ({id} seul jusqu'ici) : draftId/orderIndex/label/requestedFirm permettent au frontend
 * de normaliser son cache (remplacer l'entrée DRAFT optimiste temporaire par l'entrée
 * définitive) sans second aller-retour réseau.
 */
export type CreateInterventionTypeRequestResponse = {
  id: number;
  draftId: number;
  orderIndex: number;
  label: string;
  requestedFirm: { id: number; name: string } | null;
};

/**
 * Material lines
 * - POST   /api/missions/{missionId}/material-lines
 * - PATCH  /api/missions/{missionId}/material-lines/{lineId}
 * - DELETE /api/missions/{missionId}/material-lines/{lineId}
 *
 * `missionInterventionId`/`interventionDraftId` sont mutuellement exclusifs (jamais les
 * deux à la fois) : la cible est soit une intervention réelle, soit un draft (EPIC Revue
 * instrumentiste, Lot 3, commit 8).
 */
export type CreateMaterialLineBody = {
  missionInterventionId?: number;
  interventionDraftId?: number;
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
  missionInterventionId: number | null;
  interventionDraftId?: number | null;
  item: EncodingMaterialItem;
  quantity: string;
  comment: string;
};

/** Voir CreateMaterialLineBody — même exclusivité mutuelle. */
export type CreateMaterialItemRequestBody = {
  missionInterventionId?: number;
  interventionDraftId?: number;
  label: string;
  referenceCode?: string;
  comment?: string;
};

/**
 * Réponse réelle de POST /api/missions/{missionId}/material-item-requests :
 * {id, missionInterventionId, interventionDraftId} — les autres champs (label,
 * referenceCode, comment, status) ne sont pas renvoyés par cet endpoint, contrairement à
 * leur présence dans MissionEncodingMaterialItemRequestDto (lecture). Seul `id` (et la
 * cible, pour distinguer intervention/draft) est donc fiable ici.
 */
export type MaterialItemRequestDto = {
  id: number;
  missionInterventionId?: number | null;
  interventionDraftId?: number | null;
  label?: string;
  referenceCode?: string | null;
  comment?: string | null;
  status?: "PENDING" | "RESOLVED" | "IGNORED";
};
