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
};

export type MaterialRequestsListResponseDTO = {
  items: MaterialRequestDTO[];
  total: number;
};
