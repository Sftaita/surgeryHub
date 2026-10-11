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
  /** D-138 — ids des règles actives contradictoires ; null/absent = aucun conflit. */
  pricingConflictRuleIds?: number[] | null;
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

/**
 * D-142 — régularisation du catalogue matériel (GET /api/material-items/billing-regularisation,
 * manager/admin). Statut, compteurs et missions bloquées sont décidés par le backend
 * (FinancialCalculationService::evaluate(), lecture seule) : l'écran ne fait que les afficher.
 */
export type MaterialRegularisationStatus = "UNDECIDED" | "BILLABLE_RATE_MISSING" | "BILLABLE" | "NOT_BILLABLE";

export type MaterialRegularisationRule = {
  id: number;
  unitPrice: string;
  currency: string;
  validFrom: string | null;
  validTo: string | null;
  active: boolean;
};

export type MaterialRegularisationMission = {
  id: number;
  date: string;
  site: string | null;
  surgeon: string | null;
  lineCount: number;
  /** Toujours PENDING : seules les missions validées sans calcul actif sont listées (D-142). */
  calculationState: "PENDING";
  blockedByThisItem: boolean;
  /** Anomalies du moteur ne venant pas de cet article : > 0 = la mission reste bloquée même corrigé. */
  otherBlockingAnomalyCount: number;
};

export type MaterialRegularisationItem = {
  id: number;
  label: string;
  referenceCode: string;
  unit: string;
  isImplant: boolean;
  active: boolean;
  firm: { id: number; name: string };
  billingStatus: MaterialBillingStatus;
  status: MaterialRegularisationStatus;
  statusLabel: string;
  toConfigure: boolean;
  currentRate: { id: number; unitPrice: string; currency: string } | null;
  pricingConflict: boolean;
  rules: MaterialRegularisationRule[];
  usage: {
    lineCount: number;
    missionCount: number;
    /** Missions validées dont le calcul est actif : comptées ici, jamais réévaluées ni listées. */
    calculatedMissionCount: number;
    firstDate: string | null;
    lastDate: string | null;
    blockedMissionCount: number;
    onlyBlockerMissionCount: number;
  };
  missions: MaterialRegularisationMission[];
};

export type MaterialRegularisationResponse = {
  summary: {
    itemCount: number;
    toConfigureCount: number;
    undecidedCount: number;
    rateMissingCount: number;
    billableCount: number;
    notBillableCount: number;
    blockedMissionCount: number;
    pendingMissionCount: number;
  };
  items: MaterialRegularisationItem[];
};
