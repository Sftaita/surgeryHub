import type { Mission, UserRef } from "../api/missions.types";
import type { MissionExecutionInfo } from "../api/missions.api";
import type { EncodingComment, MissionEncodingCoherenceSummary, MissionEncodingEntry } from "../../encoding/api/encoding.types";
import type { FinancialCalculation, FinancialCalculationLineDetail } from "../../financial-calculation/api/financialCalculation.api";
import type { MissionAuditEvent } from "../../planning-v2/api/planningV2.types";
import { formatAuditEventType } from "../../audit-journal/auditEventLabels";
import { formatMinutes } from "../../encoding-tracking/encodingStateMeta";
import { formatMissionType, formatPersonLabel, formatSchedulePrecision } from "../utils/missions.format";
import { shortDateTime } from "./format";
import type { DrawerEncodingReview, DrawerIntervention, DrawerStep, Finance, FinanceGroup, FinanceLine, FinanceLineDetail, HistoryEvent, MissionDrawerView } from "./types";

/**
 * Projection des réponses API existantes vers le modèle d'affichage du tiroir. Aucune règle
 * métier : les statuts, heures, montants et événements sont ceux servis par le backend ;
 * seuls libellés, regroupements et ordres d'affichage sont décidés ici.
 */

// Étapes d'encodage atteintes, lues du statut servi par le backend.
const SUBMITTED_STATUSES = ["SUBMITTED", "DECLARED", "VALIDATED", "CLOSED"];
const VALIDATED_STATUSES = ["VALIDATED", "CLOSED"];
/** Statuts dont l'encodage n'existe pas encore (GET .../encoding n'est pas interrogé). */
export const NO_ENCODING_STATUSES = ["DRAFT", "OPEN", "CANCELLED"];
/** Le calcul financier exige une mission validée (FinancialCalculationService). */
export const FINANCE_STATUSES = ["VALIDATED", "CLOSED"];

const LINE_CODE: Record<FinancialCalculationLineDetail["lineType"], string> = {
  FIRM_INTERVENTION_FEE: "Intervention",
  FIRM_MATERIAL_FEE: "Matériel",
  INSTRUMENTIST_HOURLY: "Horaire",
  INSTRUMENTIST_CONSULTATION_FEE: "Consultation",
};

const STAGE: Record<string, 1 | 2 | 3> = { CALCULATED: 1, APPROVED: 2, LOCKED: 3 };

const CALCULATION_STATUS: Record<FinancialCalculation["status"], string> = {
  CALCULATED: "Calculé",
  APPROVED: "Approuvé",
  LOCKED: "Verrouillé",
  SUPERSEDED: "Remplacé",
  CANCELLED: "Annulé",
};

/** Lot 6 — signaux de cohérence, informationnels (jamais bloquants), lus tant que l'encodage n'est pas validé. */
const COHERENCE_LABELS: Record<keyof MissionEncodingCoherenceSummary, string> = {
  hasNoInterventions: "Aucune intervention encodée",
  hasInterventionsWithNoMaterial: "Au moins une intervention sans matériel",
  hasUnusedSuggestions: "Matériels suggérés non utilisés",
  hasMaterialFromOtherFirm: "Matériel d'une firme différente de la firme principale",
  hasMissingPrimaryFirm: "Firme principale non renseignée sur au moins une intervention",
};
const REVIEW_FLAG_STATUSES = ["ASSIGNED", "IN_PROGRESS", "ENCODING_IN_PROGRESS", "SUBMITTED"];

// Libellés des enums DisputeStatus / DisputeReasonCode (backend) ; code brut si inconnu.
const DISPUTE_STATUS: Record<string, string> = { OPEN: "ouverte", IN_REVIEW: "en cours d’examen", RESOLVED: "résolue", REJECTED: "rejetée" };
const DISPUTE_REASON: Record<string, string> = { DURATION_INCOHERENT: "Durée incohérente", WRONG_DATE: "Date erronée", DUPLICATE: "Doublon", OTHER: "Autre motif" };

function person(ref: UserRef | null | undefined) {
  return ref ? { name: formatPersonLabel(ref), photoPath: ref.profilePicturePath ?? null } : null;
}

function latest(events: MissionAuditEvent[], type: string): string | undefined {
  return events.filter((e) => e.eventType === type).reduce<string | undefined>((last, e) => (!last || e.occurredAt > last ? e.occurredAt : last), undefined);
}

function buildSteps(status: string, entries: MissionEncodingEntry[], audit: MissionAuditEvent[]): DrawerStep[] {
  const submittedDone = SUBMITTED_STATUSES.includes(status);
  const validatedDone = VALIDATED_STATUSES.includes(status);
  const draftDone = submittedDone || status === "ENCODING_IN_PROGRESS" || entries.length > 0;
  // Une date n'accompagne qu'une étape atteinte : après une réouverture, l'audit garde
  // l'ancienne soumission/validation, qui ne doit pas se lire comme l'état courant.
  return [
    { label: "Brouillon", done: draftDone, when: draftDone ? shortDateTime(latest(audit, "MISSION_ENCODING_STARTED")) : "—" },
    { label: "Soumis", done: submittedDone, when: submittedDone ? shortDateTime(latest(audit, "MISSION_ENCODING_COMPLETED")) : "—" },
    { label: "Validé", done: validatedDone, when: validatedDone ? shortDateTime(latest(audit, "MISSION_ENCODING_VALIDATED")) : "—" },
  ];
}

function representativeLabel(value: boolean | null | undefined): string | null {
  if (value === true) return "Délégué présent";
  if (value === false) return "Délégué absent";
  return null;
}

function buildInterventions(entries: MissionEncodingEntry[]): DrawerIntervention[] {
  return entries.map((entry) => {
    const materials = [
      ...entry.materialLines.map((l) => ({
        id: `ml-${l.id}`,
        name: l.item.label,
        ref: `${l.item.firm.name} · Réf. ${l.item.referenceCode}`,
        qty: Number(l.quantity) || 0,
      })),
      ...entry.materialItemRequests.map((r) => ({
        id: `mr-${r.id}`,
        name: r.label,
        ref: `Demande catalogue · Réf. ${r.referenceCode || "—"}`,
        qty: Number(r.quantity ?? 0) || 0,
      })),
    ];
    const firm = entry.firm?.name ?? (entry.kind === "DRAFT" ? entry.requestedFirmNameSnapshot ?? entry.proposedFirmName ?? null : null);
    const meta = [
      firm,
      entry.kind === "DRAFT" ? "en attente du catalogue" : representativeLabel(entry.representativePresent),
      materials.length ? `${materials.length} référence${materials.length > 1 ? "s" : ""}` : "aucun matériel",
    ].filter((v): v is string => !!v);
    return { id: `${entry.kind}-${entry.id}`, name: entry.label, meta, materials };
  });
}

/** D-092 — brut / ajustement / final et avertissements, uniquement quand la ligne en porte. */
function lineDetail(l: FinancialCalculationLineDetail): FinanceLineDetail | null {
  const adjustment = Number(l.adjustmentAmount);
  if (adjustment === 0 && l.warnings.length === 0) return null;
  const rule = l.snapshot.adjustmentReasonSnapshot;
  const representative = l.snapshot.representativePresentSnapshot;
  return {
    gross: Number(l.grossAmount),
    adjustment,
    rule: typeof rule === "string" ? rule : null,
    representativePresent: typeof representative === "boolean" ? representative : null,
    warnings: l.warnings.map((w) => w.message),
  };
}

function buildFinance(calc: FinancialCalculation, mission: Mission, previous: FinancialCalculation[]): Finance {
  const groups: FinanceGroup[] = [];
  const byKey = new Map<string, FinanceGroup>();
  for (const l of calc.lines) {
    const firm = l.beneficiaryType === "FIRM";
    const key = firm ? `F${l.beneficiaryFirmId}` : `I${l.beneficiaryInstrumentistId}`;
    let g = byKey.get(key);
    if (!g) {
      const firmName = typeof l.snapshot.firmNameSnapshot === "string" ? l.snapshot.firmNameSnapshot : null;
      g = {
        beneficiary: firm ? "firme" : "instrumentiste",
        who: firm ? firmName ?? `Firme #${l.beneficiaryFirmId}` : mission.instrumentist ? formatPersonLabel(mission.instrumentist) : "—",
        lines: [],
      };
      byKey.set(key, g);
      groups.push(g);
    }
    const code = typeof l.snapshot.interventionCodeSnapshot === "string" ? `[${l.snapshot.interventionCodeSnapshot}]` : LINE_CODE[l.lineType] ?? l.lineType;
    const label = typeof l.snapshot.interventionLabelSnapshot === "string" ? l.snapshot.interventionLabelSnapshot : l.descriptionSnapshot;
    const line: FinanceLine = {
      id: String(l.id),
      code,
      description: label,
      qtyLabel: l.lineType === "INSTRUMENTIST_HOURLY" && l.durationMinutes != null ? formatMinutes(l.durationMinutes) : String(Number(l.quantity)),
      unitPrice: Number(l.unitAmount),
      total: Number(l.totalAmount),
      currency: l.currency,
      adjusted: Number(l.adjustmentAmount) !== 0,
      detail: lineDetail(l),
    };
    g.lines.push(line);
  }
  // Firmes d'abord, instrumentiste ensuite — comme la maquette.
  groups.sort((a, b) => (a.beneficiary === b.beneficiary ? 0 : a.beneficiary === "firme" ? -1 : 1));
  return {
    id: calc.id,
    stage: STAGE[calc.status] ?? 1,
    version: `v${calc.version}`,
    calculatedAt: shortDateTime(calc.calculatedAt),
    obsolete: !!calc.obsolete,
    groups,
    previous: previous.map((c) => ({
      id: c.id,
      version: `v${c.version}`,
      status: CALCULATION_STATUS[c.status] ?? c.status,
      when: shortDateTime(c.calculatedAt),
      lines: c.lines.length,
    })),
  };
}

function buildHistory(audit: MissionAuditEvent[]): HistoryEvent[] {
  return [...audit]
    .sort((a, b) => (a.occurredAt < b.occurredAt ? 1 : -1))
    .map((e, i) => ({ id: `${e.occurredAt}-${i}`, when: shortDateTime(e.occurredAt), what: formatAuditEventType(e.eventType), who: e.actorName ?? "Système" }));
}

function buildReview(status: string, coherence: MissionEncodingCoherenceSummary | undefined, comments: EncodingComment[] | undefined): DrawerEncodingReview {
  const flags = coherence && REVIEW_FLAG_STATUSES.includes(status)
    ? (Object.keys(COHERENCE_LABELS) as (keyof MissionEncodingCoherenceSummary)[]).filter((k) => coherence[k]).map((k) => COHERENCE_LABELS[k])
    : [];
  return {
    flags,
    comments: [...(comments ?? [])]
      .sort((a, b) => (a.createdAt < b.createdAt ? 1 : -1))
      .map((c) => ({ id: c.id, text: c.comment, who: c.authorDisplayName, when: shortDateTime(c.createdAt) })),
  };
}

export function buildMissionDrawerView(input: {
  mission: Mission;
  execution: MissionExecutionInfo | undefined;
  entries: MissionEncodingEntry[] | undefined;
  coherenceSummary?: MissionEncodingCoherenceSummary;
  encodingComments?: EncodingComment[];
  encodingError: boolean;
  audit: MissionAuditEvent[] | undefined;
  calculation: FinancialCalculation | undefined;
  /** Versions précédentes du calcul (useMissionFinancialCalculation().history). */
  calculationHistory?: FinancialCalculation[];
  calculationLoading: boolean;
}): MissionDrawerView {
  const { mission, execution, encodingError, calculation, calculationLoading } = input;
  const status = String(mission.status ?? "");
  const entries = input.entries ?? [];
  const audit = input.audit ?? [];
  const without = mission.executedWithoutInstrumentist;
  const financeApplicable = FINANCE_STATUSES.includes(status);

  return {
    id: mission.id,
    status,
    type: formatMissionType(mission.type),
    site: mission.site?.name ?? "—",
    startAt: mission.startAt,
    endAt: mission.endAt,
    surgeon: person(mission.surgeon),
    instrumentist: person(mission.instrumentist),
    withoutInstrumentist: without ? `Activité déclarée${without.declaredByName ? ` par ${without.declaredByName}` : ""}` : null,
    hours: without ? null : {
      planStart: mission.startAt,
      planEnd: mission.endAt,
      real: execution?.hasExecutionRecord
        ? { start: execution.actualStartAt, end: execution.actualEndAt, minutes: execution.effectiveDurationMinutes }
        : null,
      precision: formatSchedulePrecision(mission.schedulePrecision),
      openDisputes: execution?.disputes.filter((d) => d.status === "OPEN").length ?? 0,
      disputes: (execution?.disputes ?? []).map((d) => ({
        id: d.id,
        reason: DISPUTE_REASON[d.reasonCode] ?? d.reasonCode,
        status: DISPUTE_STATUS[d.status] ?? d.status,
        open: d.status === "OPEN",
        comment: d.comment,
        resolution: d.resolutionComment,
        by: d.raisedByDisplayName,
        when: shortDateTime(d.createdAt),
      })),
    },
    steps: buildSteps(status, entries, audit),
    interventions: buildInterventions(entries),
    encodingEmpty: status === "CANCELLED"
      ? "Mission annulée — aucun encodage attendu."
      : NO_ENCODING_STATUSES.includes(status) || status === "ASSIGNED"
        ? "L’encodage s’ouvrira après la mission pour l’instrumentiste."
        : "Aucune intervention encodée.",
    encodingError,
    review: buildReview(status, input.coherenceSummary, input.encodingComments),
    finance: !financeApplicable ? null : calculationLoading ? undefined : calculation ? buildFinance(calculation, mission, input.calculationHistory ?? []) : null,
    financeApplicable,
    history: buildHistory(audit),
  };
}
