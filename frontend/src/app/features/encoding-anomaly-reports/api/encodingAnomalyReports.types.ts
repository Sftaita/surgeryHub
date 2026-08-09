/**
 * Lot 6 (D-100) — domaine dédié EncodingAnomalyReport, jamais un détournement de
 * MaterialItemRequest. V1 minimal : chirurgien crée, seul manager/admin résout (aucune
 * capacité de résolution instrumentiste dans ce lot).
 */
export type EncodingAnomalyReportType =
  | "INTERVENTION_MISSING"
  | "INTERVENTION_INCORRECT"
  | "MATERIAL_INCORRECT"
  | "HOURS_INCORRECT"
  | "OTHER";

export type EncodingAnomalyReportStatus = "OPEN" | "RESOLVED";

export type EncodingAnomalyReportUserRef = {
  id: number;
  displayName: string;
};

export type EncodingAnomalyReport = {
  id: number;
  missionId: number;
  reporter: EncodingAnomalyReportUserRef | null;
  type: EncodingAnomalyReportType;
  comment: string;
  status: EncodingAnomalyReportStatus;
  createdAt: string;
  resolvedBy: EncodingAnomalyReportUserRef | null;
  resolvedAt: string | null;
  resolutionComment: string | null;
};

export type CreateEncodingAnomalyReportBody = {
  type: EncodingAnomalyReportType;
  comment: string;
};

export type ResolveEncodingAnomalyReportBody = {
  resolutionComment: string;
};

/** Ordre d'affichage des boutons radio — reflète App\Entity\EncodingAnomalyReport::TYPES. */
export const ENCODING_ANOMALY_REPORT_TYPES: EncodingAnomalyReportType[] = [
  "INTERVENTION_MISSING",
  "INTERVENTION_INCORRECT",
  "MATERIAL_INCORRECT",
  "HOURS_INCORRECT",
  "OTHER",
];

export const ENCODING_ANOMALY_REPORT_TYPE_LABELS: Record<EncodingAnomalyReportType, string> = {
  INTERVENTION_MISSING: "Intervention manquante",
  INTERVENTION_INCORRECT: "Intervention incorrecte",
  MATERIAL_INCORRECT: "Matériel incorrect",
  HOURS_INCORRECT: "Heures incorrectes",
  OTHER: "Autre",
};
