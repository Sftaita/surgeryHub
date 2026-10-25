/**
 * Modèle d'AFFICHAGE du tiroir mission (maquette docs/design/Instruction design/Mission-drawer).
 * Construit par missionDrawerModel.ts à partir des réponses API existantes — aucun champ
 * n'est inventé ni renvoyé au serveur.
 */
export interface DrawerMaterial { id: string; name: string; ref: string; qty: number; }
export interface DrawerIntervention { id: string; name: string; meta: string[]; materials: DrawerMaterial[]; }

export interface DrawerStep { label: string; when: string; done: boolean; }

/** Ligne financière telle que renvoyée par l'API — montants TOUJOURS positifs. */
export interface FinanceLine {
  id: string;
  code: string;
  description: string;
  qtyLabel: string;
  unitPrice: number;
  total: number;
  currency: string;
  adjusted: boolean;
  /** Détail servi par l'API, affiché sous la ligne quand elle est ajustée ou signalée. */
  detail: FinanceLineDetail | null;
}
export interface FinanceLineDetail {
  gross: number;
  adjustment: number;
  /** Règle d'ajustement (politique délégué) telle que figée dans le snapshot. */
  rule: string | null;
  representativePresent: boolean | null;
  warnings: string[];
}
export interface FinanceVersion { id: number; version: string; status: string; when: string; lines: number; }
export interface FinanceGroup { beneficiary: 'firme' | 'instrumentiste'; who: string; lines: FinanceLine[]; }
/** 1 = calculé · 2 = approuvé · 3 = verrouillé */
export interface Finance {
  id: number;
  stage: 1 | 2 | 3;
  version: string;
  calculatedAt: string;
  /** D-153 — encodage rouvert depuis ce calcul : ni approuvable, ni verrouillable. */
  obsolete: boolean;
  groups: FinanceGroup[];
  /** Versions précédentes (remplacées / annulées). */
  previous: FinanceVersion[];
}

export interface HistoryEvent { id: string; when: string; what: string; who: string; }

export interface DrawerHours {
  planStart: string;
  planEnd: string;
  /** null = aucune heure déclarée. start/end null = durée seule (ACTUAL_EXPLICIT). */
  real: { start: string | null; end: string | null; minutes: number } | null;
  precision: string;
  openDisputes: number;
  disputes: DrawerDispute[];
}
export interface DrawerDispute { id: number; reason: string; status: string; open: boolean; comment: string | null; resolution: string | null; by: string; when: string; }

/** Lot 7 (D-070) — signaux de cohérence (informationnels) et commentaires manager. */
export interface DrawerEncodingReview { flags: string[]; comments: { id: number; text: string; who: string; when: string }[]; }

export interface MissionDrawerView {
  id: number;
  status: string;
  type: string;
  site: string;
  /** ISO */
  startAt: string;
  endAt: string;
  surgeon: { name: string; photoPath: string | null } | null;
  instrumentist: { name: string; photoPath: string | null } | null;
  /** D-155 — activité exécutée sans instrumentiste (libellé « déclarée par… »). */
  withoutInstrumentist: string | null;
  hours: DrawerHours | null;
  steps: DrawerStep[];
  interventions: DrawerIntervention[];
  encodingEmpty: string;
  encodingError: boolean;
  review: DrawerEncodingReview;
  /** null = calcul non applicable (mission non validée) ; undefined = en chargement. */
  finance: Finance | null | undefined;
  financeApplicable: boolean;
  history: HistoryEvent[];
}
