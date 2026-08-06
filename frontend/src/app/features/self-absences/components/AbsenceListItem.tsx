import { Box, Stack, Typography } from "@mui/material";
import EditOutlinedIcon from "@mui/icons-material/EditOutlined";
import DeleteOutlineIcon from "@mui/icons-material/DeleteOutline";
import dayjs from "dayjs";
import "dayjs/locale/fr";

import type { SelfAbsence } from "../api/selfAbsences.types";

dayjs.locale("fr");

const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_900 = "#16202B";
const BORDER_SUBTLE = "#E7EBEF";
const GREEN_700 = "#2C7D5F";

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}

function durationDays(a: SelfAbsence): number {
  return Math.round((dayjs(a.dateEnd).valueOf() - dayjs(a.dateStart).valueOf()) / 86_400_000) + 1;
}

/**
 * Single shared row for both roles (Lot 3, D-097, §7) — a single day shows one date; a
 * period shows "début → fin" plus a discreet duration. Never displays the backend's
 * dateStart===dateEnd convention directly (§4 — never exposed to the user).
 */
export function AbsenceListItem({
  absence,
  onEdit,
  onDelete,
}: {
  absence: SelfAbsence;
  onEdit: () => void;
  onDelete: () => void;
}) {
  const isSingleDay = absence.dateStart === absence.dateEnd;
  const dateLabel = isSingleDay
    ? capitalize(dayjs(absence.dateStart).format("D MMMM"))
    : `${dayjs(absence.dateStart).format("D MMM")} → ${dayjs(absence.dateEnd).format("D MMM")}`;

  return (
    <Box
      sx={{
        display: "flex", alignItems: "center", gap: "12px", width: "100%",
        background: "#fff", border: `1px solid ${BORDER_SUBTLE}`, borderRadius: "16px",
        padding: "14px 16px", boxShadow: "0 1px 2px rgba(22,32,43,.05)",
      }}
    >
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700, color: GRAY_900 }} noWrap>
          {dateLabel}
        </Typography>
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: "3px" }}>
          <Typography sx={{ fontSize: 13, color: GRAY_600 }} noWrap>
            {absence.reason ?? "Sans motif précisé"}
          </Typography>
          {!isSingleDay && (
            <Typography sx={{ fontSize: 12, color: GRAY_500, flexShrink: 0 }}>
              · {durationDays(absence)} jours
            </Typography>
          )}
        </Stack>
      </Box>

      {absence.editable ? (
        <Stack direction="row" spacing={0.5} flexShrink={0}>
          <Box
            component="button" type="button" onClick={onEdit} aria-label="Modifier"
            sx={{
              width: 34, height: 34, border: "none", borderRadius: "10px", background: "transparent",
              color: GREEN_700, cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center",
              "&:active": { transform: "scale(.94)" },
            }}
          >
            <EditOutlinedIcon fontSize="small" />
          </Box>
          <Box
            component="button" type="button" onClick={onDelete} aria-label="Supprimer"
            sx={{
              width: 34, height: 34, border: "none", borderRadius: "10px", background: "transparent",
              color: "#C62F36", cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center",
              "&:active": { transform: "scale(.94)" },
            }}
          >
            <DeleteOutlineIcon fontSize="small" />
          </Box>
        </Stack>
      ) : (
        <Typography sx={{ fontSize: 11, color: GRAY_500, flexShrink: 0 }}>Passée</Typography>
      )}
    </Box>
  );
}
