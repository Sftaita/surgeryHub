export type FirmDTO = {
  id: number;
  name: string;
  /** Chemin racine-relatif ("/uploads/firm-logos/..."), résoudre via resolveApiAssetUrl(). */
  logoPath?: string | null;
};

/** Refonte Catalogue/Prestations (D-092) — distingue "volontairement non facturé" de "tarif pas encore configuré". */
export type MaterialBillingStatus = "UNSPECIFIED" | "BILLABLE" | "NOT_BILLABLE";

export type MaterialItemDTO = {
  id: number;
  firm: FirmDTO | null;
  label: string;
  referenceCode: string;
  unit: string;
  isImplant: boolean;
  billingStatus: MaterialBillingStatus;
  /**
   * Point 10 (audit tarification) — présent uniquement pour manager/admin (backend
   * RBAC, MaterialCatalogController::list()) ; absent (jamais juste `null`) pour tout
   * autre rôle — voir MaterialCatalogControllerTest::test_instrumentist_never_sees_current_price.
   */
  currentPrice?: string | null;
  currentCurrency?: string | null;
};

export type MaterialItemsListResponseDTO = {
  items: MaterialItemDTO[];
  total: number;
  page: number;
  limit: number;
};

export type CreateMaterialItemBody = {
  firmId: number;
  label: string;
  unit: string;
  referenceCode?: string;
  isImplant: boolean;
};

export type UpdateMaterialItemBody = {
  firmId?: number;
  label?: string;
  unit?: string;
  referenceCode?: string;
  isImplant?: boolean;
  billingStatus?: MaterialBillingStatus;
};

export type MaterialRequestStatus = "PENDING" | "RESOLVED" | "IGNORED";

/**
 * Correctif workflow Demandes Catalogue (D-113) — motif structuré, partagé avec
 * InterventionTypeRequestDTO (interventionTypeRequests.api.ts). ALREADY_EXISTS
 * (intervention) et MATERIAL_ALREADY_EXISTS (matériel) sont volontairement distincts :
 * chaque kind n'affiche que le motif "déjà existant" qui le concerne dans le Select de la
 * modal Ignorer (voir IgnoreCatalogueRequestDialog).
 */
export type CatalogueRequestIgnoreReason =
  | "ALREADY_EXISTS"
  | "MATERIAL_ALREADY_EXISTS"
  | "DUPLICATE"
  | "INVALID_REQUEST"
  | "OTHER";

export type MaterialRequestDTO = {
  id: number;
  status: MaterialRequestStatus;
  label: string;
  referenceCode: string | null;
  comment: string | null;
  createdAt: string;
  mission: {
    id: number;
    site: string | null;
  } | null;
  requestedBy: {
    id: number;
    displayName: string;
  } | null;
  materialItem: MaterialItemDTO | null;
  /** Renseignés uniquement pour une demande IGNORED (D-113). */
  ignoreReason: CatalogueRequestIgnoreReason | null;
  ignoreComment: string | null;
  decidedBy: { id: number; displayName: string } | null;
  decidedAt: string | null;
};

export type MaterialRequestsListResponseDTO = {
  items: MaterialRequestDTO[];
  total: number;
};
