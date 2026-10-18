import { Box } from "@mui/material";
import dayjs from "dayjs";

import { formatMinutes, type PrestationView } from "../api/prestations.api";

const GRAY_150 = "#EEF1F4";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_700 = "#3B4856";
const GRAY_800 = "#243240";
const GREEN_50 = "#EFFAF5";
const GREEN_700 = "#2C7D5F";
const BLUE_50 = "#EDF4FF";
const BLUE_700 = "#1B5FD0";

type Props = {
  prestation: PrestationView;
  /** Mission dont les interventions sont affichées — choisie explicitement, jamais par défaut. */
  selectedMissionId: number | null;
  onSelectMission: (missionId: number) => void;
  canEditHours: boolean;
  onEditHours: () => void;
};

/**
 * D-156 — en-tête d'une prestation regroupée multi-salles : les missions restent distinctes
 * (chacune identifiée sans ambiguïté), une seule présence réelle est saisie, et la mission à
 * laquelle on ajoute des interventions est choisie explicitement (jamais « la première »).
 */
export function PrestationPanel({ prestation, selectedMissionId, onSelectMission, canEditHours, onEditHours }: Props) {
  const lockedIds = prestation.hoursLockedByMissionIds ?? [];
  const lockedBy = prestation.missions.filter((m) => lockedIds.includes(m.id));
  const presence = prestation.presence;
  const hoursComplete = presence?.actualStartAt != null && presence?.actualEndAt != null;

  return (
    <Box data-testid="prestation-panel" sx={{ display: "flex", flexDirection: "column", gap: "14px" }}>
      <Box sx={{ background: BLUE_50, borderRadius: "16px", padding: "14px 16px" }}>
        <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: ".08em", textTransform: "uppercase", color: BLUE_700 }}>
          Prestation multi-salles
        </Box>
        <Box sx={{ mt: "4px", fontSize: 15, fontWeight: 700, color: GRAY_800 }}>
          {prestation.surgeon?.name ?? "—"} — {prestation.size} missions —{" "}
          {dayjs(prestation.planned.startAt).format("HH[h]mm")}–{dayjs(prestation.planned.endAt).format("HH[h]mm")}
        </Box>
        <Box sx={{ mt: "2px", fontSize: 13, color: GRAY_600 }}>
          Temps planifié global : {formatMinutes(prestation.planned.unionMinutes)} · {prestation.site.name}
        </Box>
      </Box>

      {/* Zone 1 — temps de travail : UNE présence pour toutes les missions. */}
      <Box sx={{ background: GRAY_150, borderRadius: "16px", padding: "12px 13px" }}>
        <Box sx={{ fontSize: 11.5, fontWeight: 800, letterSpacing: ".1em", textTransform: "uppercase", color: GRAY_700 }}>
          Temps de travail · 1 saisie pour la prestation
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
                ? `Présence réelle ${formatMinutes(presence?.totalMinutes)} — répartie entre les missions ci-dessous`
                : "À saisir une seule fois : elle sera répartie entre les missions."}
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
        {!canEditHours && lockedBy.length > 0 && lockedBy.length < prestation.missions.length && (
          <Box sx={{ mt: "8px", fontSize: 12.5, color: GRAY_700 }} data-testid="prestation-hours-locked">
            Heures communes non modifiables : {lockedBy.map((m) => m.label).join(", ")}{" "}
            {lockedBy.length > 1 ? "sont déjà soumises ou validées" : "est déjà soumise ou validée"}. Pour corriger les heures,
            demandez au manager de {lockedBy.length > 1 ? "les" : "la"} renvoyer aussi en correction.
          </Box>
        )}
      </Box>

      {/* Missions distinctes — choix explicite de celle dont on encode les interventions. */}
      <Box>
        <Box sx={{ fontSize: 12.5, fontWeight: 700, color: GRAY_700, mb: "8px" }}>
          {selectedMissionId === null
            ? "Choisissez la mission (salle) dont vous encodez les interventions et le matériel :"
            : "Interventions et matériel de la mission :"}
        </Box>
        <Box role="radiogroup" aria-label="Mission de la prestation" sx={{ display: "flex", flexDirection: "column", gap: "8px" }}>
          {prestation.missions.map((m) => {
            const selected = m.id === selectedMissionId;
            return (
              <Box
                key={m.id}
                component="button"
                type="button"
                role="radio"
                aria-checked={selected}
                data-testid={`prestation-mission-${m.id}`}
                onClick={() => onSelectMission(m.id)}
                sx={{
                  textAlign: "left", fontFamily: "inherit", cursor: "pointer",
                  border: selected ? `2px solid ${GREEN_700}` : "1px solid #D9DEE4",
                  background: selected ? GREEN_50 : "#fff", borderRadius: "12px", padding: selected ? "9px 11px" : "10px 12px",
                }}
              >
                <Box sx={{ fontSize: 14, fontWeight: 700, color: GRAY_800 }}>{m.label}</Box>
                <Box sx={{ fontSize: 12.5, color: GRAY_500, mt: "2px" }}>
                  {m.interventionCount} intervention(s) · {m.activeMaterialLineCount} ligne(s) de matériel
                  {m.imputedMinutes != null ? ` · part imputée ${formatMinutes(m.imputedMinutes)}` : ""}
                  {m.status === "SUBMITTED" ? " · soumise" : ""}
                </Box>
              </Box>
            );
          })}
        </Box>
      </Box>
    </Box>
  );
}
