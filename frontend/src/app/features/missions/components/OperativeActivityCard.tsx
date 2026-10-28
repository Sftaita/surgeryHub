import type { Mission } from "../api/missions.types";
import { encodingActorLabel } from "./MissionEncodingBadge";

const GRAY_500 = "#727E8C";
const GRAY_800 = "#243240";
const GREEN_800 = "#1F6B4F";
const AMBER_700 = "#9A6212";
const BLUE_700 = "#1B5FD0";

export interface OperativeActivity {
  declaredBy: string | null;
  /** « Encodage terminé par le chirurgien — Dr X » ou « Encodage finalisé par X (manager) » ; null = non finalisé. */
  finalized: string | null;
  validation: { label: string; tone: "progress" | "pending" | "done" };
  counts: string | null;
  blockedReason: string | null;
  realHours: string | null;
}

function hhmm(iso: string): string {
  return new Intl.DateTimeFormat("fr-BE", { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Brussels" }).format(new Date(iso));
}

/**
 * D-172 — activité déclarée réalisée sans instrumentiste (D-155) : ce que le manager doit lire à
 * la place du suivi des heures instrumentiste. Uniquement des faits servis par le backend
 * (`encoding` : état, auteur de la finalisation et de la validation, compteurs). Des interventions
 * enregistrées ne valent jamais finalisation : seul `finalizedBy` (audit MISSION_ENCODING_COMPLETED)
 * le dit. Null si aucune activité n'a été déclarée.
 */
export function buildOperativeActivity(
  mission: Pick<Mission, "executedWithoutInstrumentist" | "encoding" | "encodingValidationBlockedReason" | "encodingCorrectionBlockedReason">,
  real?: { start: string | null; end: string | null } | null,
): OperativeActivity | null {
  const declared = mission.executedWithoutInstrumentist;
  if (!declared) return null;
  const encoding = mission.encoding ?? null;
  const state = encoding?.state;
  const finalizedBy = state === "SUBMITTED" || state === "VALIDATED" || state === "LOCKED" ? encoding?.finalizedBy ?? null : null;
  const finalized = finalizedBy
    ? finalizedBy.role === "SURGEON"
      ? `Encodage terminé par le chirurgien — ${finalizedBy.name ?? ""}`.trim()
      : `Encodage finalisé par ${encodingActorLabel(finalizedBy)}`
    : null;
  const validatedBy = encodingActorLabel(encoding?.validatedBy);
  const validation: OperativeActivity["validation"] = state === "SUBMITTED"
    ? { label: "À valider", tone: "pending" }
    : state === "VALIDATED" || state === "LOCKED"
      ? { label: validatedBy ? `Validé par ${validatedBy}` : "Validé", tone: "done" }
      : { label: "Encodage en cours — pas encore finalisé", tone: "progress" };
  const n = encoding?.interventionCount;
  const l = encoding?.materialLineCount;
  const counts = n == null || l == null
    ? null
    : `${n} intervention${n > 1 ? "s" : ""} · ${l} ligne${l > 1 ? "s" : ""} de matériel enregistrée${l > 1 ? "s" : ""}`;

  return {
    declaredBy: declared.declaredByName,
    finalized,
    validation,
    counts,
    blockedReason: mission.encodingValidationBlockedReason ?? mission.encodingCorrectionBlockedReason ?? null,
    realHours: real?.start && real?.end ? `${hhmm(real.start)} – ${hhmm(real.end)}` : null,
  };
}

const TONE = { progress: AMBER_700, pending: BLUE_700, done: GREEN_800 };

/** Contenu du bloc « Activité opératoire » ; le cadre (carte) est fourni par l'écran hôte. */
export function OperativeActivityBody({ activity, financeText }: { activity: OperativeActivity; financeText?: string | null }) {
  const line = { display: "block", fontSize: 13, lineHeight: 1.5, color: GRAY_800 } as const;
  return (
    <div data-testid="drawer-operative-activity" style={{ display: "flex", flexDirection: "column", gap: 4 }}>
      <span style={{ ...line, fontWeight: 700 }}>
        Mission réalisée sans instrumentiste{activity.declaredBy ? ` — déclarée par ${activity.declaredBy}` : ""}
      </span>
      <span style={{ ...line, fontWeight: 700, color: activity.finalized ? GREEN_800 : AMBER_700 }} data-testid="operative-activity-finalized">
        {activity.finalized ?? "Encodage en cours — pas encore finalisé"}
      </span>
      {activity.finalized && (
        <span style={{ ...line, fontWeight: 700, color: TONE[activity.validation.tone] }} data-testid="operative-activity-validation">
          {activity.validation.label}
        </span>
      )}
      {activity.counts && <span style={line}>{activity.counts}</span>}
      {financeText && <span style={line}>Calcul financier : {financeText}</span>}
      {activity.blockedReason && <span style={{ ...line, color: GRAY_500 }} data-testid="operative-activity-blocked">{activity.blockedReason}</span>}
      {activity.realHours && <span style={line}>Horaires opératoires réels : {activity.realHours}</span>}
      <span style={{ ...line, fontSize: 12, color: GRAY_500 }}>
        Aucune prestation d'instrumentiste : seules les prestations applicables aux firmes sont valorisées.
      </span>
    </div>
  );
}
