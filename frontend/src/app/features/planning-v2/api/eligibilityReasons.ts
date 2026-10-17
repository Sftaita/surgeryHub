// D-101/D-102 — single shared reason-label map for every instrumentist eligibility
// display (ghost rows, rejected-assignment toasts, reassign dialogs). Mirrors backend
// App\Enum\EligibilityReason::label() — do not fork per-component copies.
import type { CandidateEligibility, EligibilityReason } from "./planningV2.types";

export const ELIGIBILITY_REASON_LABELS: Record<EligibilityReason, string> = {
  INACTIVE: "Compte inactif",
  NO_SITE_MEMBERSHIP: "Non affiliée à ce site",
  ABSENT: "Absente",
  SCHEDULE_CONFLICT: "Conflit d'horaire",
  ALREADY_ASSIGNED: "Déjà assignée",
  INCOMPATIBLE_STATUS: "Statut incompatible",
  // D-151 — manager-facing pickers only (the instrumentist never sees this reason).
  REFUSED_FOR_MISSION: "Refusée par le chirurgien pour cette mission",
  MISSION_ALREADY_STARTED: "Mission déjà commencée",
  SURGEON_INCOMPATIBILITY: "Incompatible avec le chirurgien",
};

export function eligibilityReasonLabel(reason: EligibilityReason | string): string {
  return ELIGIBILITY_REASON_LABELS[reason as EligibilityReason] ?? reason;
}

// Compact-display priority when only one reason can be shown (e.g. a ghost row's
// single badge) — most actionable/severe first. NO_SITE_MEMBERSHIP is deliberately
// last: per D-101 it's informational-only and never blocking.
const REASON_PRIORITY: EligibilityReason[] = [
  "SURGEON_INCOMPATIBILITY",
  "REFUSED_FOR_MISSION",
  "INACTIVE",
  "ABSENT",
  "SCHEDULE_CONFLICT",
  "NO_SITE_MEMBERSHIP",
  "ALREADY_ASSIGNED",
  "INCOMPATIBLE_STATUS",
  "MISSION_ALREADY_STARTED",
];

export function primaryReason(reasons: EligibilityReason[]): EligibilityReason | null {
  if (reasons.length === 0) return null;
  for (const r of REASON_PRIORITY) {
    if (reasons.includes(r)) return r;
  }
  return reasons[0];
}

/** Formats the reason badge shown on a ghost row, e.g. "Absente · 01/08 → 16/08". */
export function candidateGhostLabel(candidate: CandidateEligibility): string | null {
  const reason = primaryReason(candidate.reasons);
  if (!reason) return null;
  const label = eligibilityReasonLabel(reason);
  if (reason === "ABSENT" && candidate.unavailability) {
    return `${label} · ${formatFrDate(candidate.unavailability.dateStart)} → ${formatFrDate(candidate.unavailability.dateEnd)}`;
  }
  if (reason === "SCHEDULE_CONFLICT" && candidate.conflict) {
    const site = candidate.conflict.siteName ? ` (${candidate.conflict.siteName})` : "";
    return `${label}${site}`;
  }
  return label;
}

function formatFrDate(iso: string): string {
  const d = iso.slice(0, 10).split("-");
  if (d.length !== 3) return iso;
  return `${d[2]}/${d[1]}`;
}
