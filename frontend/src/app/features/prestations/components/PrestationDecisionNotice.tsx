import { Alert } from "@mui/material";

import type { MissionPrestationSummary } from "../../missions/api/missions.types";
import { prestationError } from "../api/prestations.api";

/**
 * Complément D-156 — une décision du manager (valider, renvoyer en correction, rouvrir,
 * terminer et valider) sur une mission d'une prestation multi-salles s'applique à toute la
 * prestation, en une seule opération (serveur). La fenêtre de confirmation le dit.
 */
export function PrestationDecisionNotice({ prestation }: { prestation?: Pick<MissionPrestationSummary, "missionIds"> | null }) {
  if (!prestation || prestation.missionIds.length < 2) return null;

  return (
    <Alert severity="info" sx={{ mb: 1.5 }} data-testid="prestation-decision-notice">
      Prestation multi-salles · {prestation.missionIds.length} missions (n° {prestation.missionIds.join(", ")}) :
      cette décision s'applique à toute la prestation.
    </Alert>
  );
}

/** Message serveur d'un refus propre à la prestation (409 PRESTATION_*), sinon null. */
export function prestationDecisionErrorText(err: unknown): string | null {
  const e = prestationError(err);
  return e.code?.startsWith("PRESTATION_") ? e.message : null;
}

/**
 * Complément D-156 — pourquoi l'ajout n'est pas proposé sur cette mission (manager) : le
 * nouveau contenu d'une prestation multi-salles va sur sa mission de référence (serveur).
 */
export function referenceNotice(prestation?: { referenceMissionId: number; acceptsNewContent: boolean } | null): string | null {
  if (!prestation || prestation.acceptsNewContent) return null;
  return `Prestation multi-salles : les interventions et le matériel s'ajoutent sur la mission de référence n° ${prestation.referenceMissionId}. Ici, seul l'existant peut être modifié.`;
}
