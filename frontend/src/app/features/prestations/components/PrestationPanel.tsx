import { Box } from "@mui/material";
import dayjs from "dayjs";

import { formatMinutes, type PrestationView } from "../api/prestations.api";

const GRAY_150 = "#EEF1F4";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_700 = "#3B4856";
const GRAY_800 = "#243240";
const GREEN_700 = "#2C7D5F";
const BLUE_50 = "#EDF4FF";
const BLUE_700 = "#1B5FD0";

type Props = {
  prestation: PrestationView;
  canEditHours: boolean;
  onEditHours: () => void;
};

/**
 * D-156 — en-tête d'une prestation regroupée multi-salles : une seule présence réelle est
 * saisie pour toute la journée. Complément D-156 : l'instrumentiste ne choisit jamais de
 * salle ni de mission — un seul encodage (interventions et matériel) pour la prestation,
 * enregistré par le serveur sur sa mission de référence. Les missions restent distinctes
 * pour le planning et la répartition des heures, visibles côté manager uniquement.
 */
export function PrestationPanel({ prestation, canEditHours, onEditHours }: Props) {
  const lockedIds = prestation.hoursLockedByMissionIds ?? [];
  const presence = prestation.presence;
  const hoursComplete = presence?.actualStartAt != null && presence?.actualEndAt != null;
  const hoursLocked = !canEditHours && lockedIds.length > 0;

  return (
    <Box data-testid="prestation-panel" sx={{ display: "flex", flexDirection: "column", gap: "14px" }}>
      <Box sx={{ background: BLUE_50, borderRadius: "16px", padding: "14px 16px" }}>
        <Box data-testid="prestation-kicker" sx={{ fontSize: 12, fontWeight: 800, letterSpacing: ".08em", textTransform: "uppercase", color: BLUE_700 }}>
          Multi-salles · {prestation.size} missions
        </Box>
        <Box sx={{ mt: "4px", fontSize: 15, fontWeight: 700, color: GRAY_800 }}>
          {prestation.surgeon?.name ?? "—"} —{" "}
          {dayjs(prestation.planned.startAt).format("HH[h]mm")}–{dayjs(prestation.planned.endAt).format("HH[h]mm")}
        </Box>
        <Box sx={{ mt: "2px", fontSize: 13, color: GRAY_600 }}>
          Temps planifié global : {formatMinutes(prestation.planned.unionMinutes)} · {prestation.site.name}
        </Box>
        <Box sx={{ mt: "6px", fontSize: 12.5, color: GRAY_600 }}>
          Une seule journée de travail : vos heures, vos interventions et votre matériel s'encodent une seule fois.
        </Box>
      </Box>

      {/* Zone 1 — temps de travail : UNE présence pour toute la prestation. */}
      <Box sx={{ background: GRAY_150, borderRadius: "16px", padding: "12px 13px" }}>
        <Box sx={{ fontSize: 11.5, fontWeight: 800, letterSpacing: ".1em", textTransform: "uppercase", color: GRAY_700 }}>
          Temps de travail · 1 saisie
        </Box>
        <Box sx={{ mt: "10px", background: "#fff", borderRadius: "12px", padding: "12px 14px", display: "flex", alignItems: "center", gap: "12px" }}>
          <Box sx={{ flex: 1 }}>
            <Box sx={{ fontSize: 14, fontWeight: 700, color: GRAY_800 }} data-testid="prestation-presence">
              {presence?.actualStartAt
                ? `${dayjs(presence.actualStartAt).format("HH[h]mm")} → ${presence.actualEndAt ? dayjs(presence.actualEndAt).format("HH[h]mm") : "…"}`
                : "Heures réelles non renseignées"}
            </Box>
            <Box sx={{ fontSize: 12.5, color: GRAY_500, mt: "2px" }}>
              {hoursComplete
                ? `Présence réelle ${formatMinutes(presence?.totalMinutes)}`
                : "Début et fin de votre journée, à saisir une seule fois."}
            </Box>
          </Box>
          {canEditHours && (
            <Box
              component="button"
              type="button"
              onClick={onEditHours}
              sx={{ border: "none", borderRadius: "10px", background: GREEN_700, color: "#fff", fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, padding: "9px 14px", cursor: "pointer" }}
            >
              {presence ? "Modifier" : "Renseigner"}
            </Box>
          )}
        </Box>
        {hoursLocked && lockedIds.length < prestation.missions.length && (
          <Box sx={{ mt: "8px", fontSize: 12.5, color: GRAY_700 }} data-testid="prestation-hours-locked">
            Heures non modifiables : une partie de la prestation est déjà soumise ou validée. Pour corriger les heures,
            demandez au manager de renvoyer la prestation en correction.
          </Box>
        )}
      </Box>
    </Box>
  );
}
