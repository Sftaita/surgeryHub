import { Box } from "@mui/material";
import dayjs from "dayjs";

import type { MissionExecutionInfo } from "../../missions/api/missions.api";

const GRAY_100 = "#F1F4F7";
const GRAY_400 = "#98A2AE";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#5A6675";
const GRAY_700 = "#3A4754";
const GRAY_950 = "#0B1320";
const GRAY_50 = "#F5F7FA";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

function ClockIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3.5 2" />
    </svg>
  );
}
function ChevronRightIcon() {
  return (
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.6} strokeLinecap="round" strokeLinejoin="round">
      <path d="m9 6 6 6-6 6" />
    </svg>
  );
}

/** "4h00" — le pendant tabulaire de fmtDuration() (EditServiceHoursDialog.tsx), pour le
 *  bloc TOTAL NET. Local à ce composant : formatExecutionHours() (missions.format.ts)
 *  reste le format "X h" partagé par SubmitDialog/instrumentist MissionDetailPage. */
function formatHm(minutes: number): string {
  return `${Math.floor(minutes / 60)}h${String(minutes % 60).padStart(2, "0")}`;
}

/**
 * Détail sous le libellé. Le backend ne stocke qu'une durée décimale pour une saisie
 * instrumentiste (EditServiceHoursDialog n'envoie jamais actualStartAt/actualEndAt) —
 * on ne peut donc pas reconstruire "10h45 → 18h15 · pause 45 min" (DOCUMENTATION §2)
 * pour le cas réel. Si un horaire réel existe malgré tout (autre source), on l'affiche ;
 * sinon on reste honnête plutôt que d'inventer une plage.
 */
function detailLabel(execution: MissionExecutionInfo | null | undefined): string {
  if (!execution?.hasExecutionRecord) return "Non renseignées — appuyez pour saisir";
  if (execution.actualStartAt && execution.actualEndAt) {
    return `${dayjs(execution.actualStartAt).format("HH[h]mm")} → ${dayjs(execution.actualEndAt).format("HH[h]mm")}`;
  }
  return "Saisies manuellement";
}

type Props = {
  execution: MissionExecutionInfo | null | undefined;
  /** Masque le chevron et désactive le clic — même garde que l'ancien bloc inline. */
  canEdit: boolean;
  onOpen: () => void;
};

/**
 * Carte "Heures prestées" (zone 1) — docs/design/Instruction design/Page encodage
 * instrumentiste, DOCUMENTATION.md §2. Volontairement neutre (blanc + gris, aucune
 * touche de vert, pas de bandeau foncé) : c'est ce qui la distingue au premier coup
 * d'œil des cartes intervention (zone 2).
 */
export function WorkedHoursCard({ execution, canEdit, onOpen }: Props) {
  const filled = !!execution?.hasExecutionRecord;
  const total = filled && execution?.actualDurationMinutes != null ? formatHm(execution.actualDurationMinutes) : null;

  return (
    <Box
      component="button"
      type="button"
      onClick={() => canEdit && onOpen()}
      sx={{
        display: "flex", alignItems: "center", width: "100%",
        gap: "12px", padding: "13px",
        border: 0, borderRadius: "13px", background: "#fff", boxShadow: SHADOW_XS,
        fontFamily: "inherit", textAlign: "left", cursor: canEdit ? "pointer" : "default",
        transition: "background 120ms", WebkitTapHighlightColor: "transparent",
        "&:hover": canEdit ? { background: GRAY_50 } : undefined,
        "@media (min-width:600px)": { gap: "16px", padding: "15px 16px" },
      }}
    >
      <Box sx={{ width: 40, height: 40, flexShrink: 0, borderRadius: "11px", background: GRAY_100, color: GRAY_700, display: "grid", placeItems: "center" }}>
        <ClockIcon />
      </Box>
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Box sx={{ fontSize: 15, fontWeight: 700, color: GRAY_950 }}>Heures prestées</Box>
        <Box sx={{ mt: "3px", fontSize: 12.5, fontWeight: 600, color: GRAY_500, fontVariantNumeric: "tabular-nums" }}>
          {detailLabel(execution)}
        </Box>
      </Box>
      {total && (
        <Box sx={{ flexShrink: 0, textAlign: "right" }}>
          <Box sx={{ fontSize: 21, fontWeight: 800, lineHeight: 1, letterSpacing: "-.02em", color: GRAY_950, fontVariantNumeric: "tabular-nums", "@media (min-width:600px)": { fontSize: 24 } }}>
            {total}
          </Box>
          <Box sx={{ display: "none", mt: "3px", fontSize: 11, fontWeight: 700, letterSpacing: ".06em", color: GRAY_400, "@media (min-width:600px)": { display: "block" } }}>
            TOTAL NET
          </Box>
        </Box>
      )}
      {canEdit && (
        <Box sx={{ width: 32, height: 32, flexShrink: 0, borderRadius: "999px", background: GRAY_100, color: GRAY_600, display: "grid", placeItems: "center" }}>
          <ChevronRightIcon />
        </Box>
      )}
    </Box>
  );
}

export default WorkedHoursCard;
