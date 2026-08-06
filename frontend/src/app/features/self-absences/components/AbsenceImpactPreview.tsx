import { useQuery } from "@tanstack/react-query";
import { Box, CircularProgress, Stack, Typography } from "@mui/material";
import dayjs from "dayjs";
import "dayjs/locale/fr";

import { fetchAbsenceImpactPreview } from "../api/selfAbsences.api";

dayjs.locale("fr");

const GREEN_700 = "#2C7D5F";
const GREEN_50 = "#EFFAF5";
const AMBER_700 = "#B7791F";
const AMBER_50 = "#FEF6E7";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_900 = "#16202B";

function formatTime(iso: string): string {
  return dayjs(iso).format("HH:mm");
}

/**
 * Impact preview (Lot 3, D-097, §9) — reuses AbsenceImpactService::previewOverlappingMissions()
 * via GET /api/absences/mine/impact-preview, never a frontend-side conflict engine. Purely
 * informational: never blocks submission (§10) — the caller decides when to show/hide this,
 * this component only renders whatever the backend currently reports for the given range.
 */
export function AbsenceImpactPreview({
  dateStart,
  dateEnd,
  enabled,
}: {
  dateStart: string;
  dateEnd: string;
  enabled: boolean;
}) {
  const query = useQuery({
    queryKey: ["self-absences", "impact-preview", dateStart, dateEnd],
    queryFn: () => fetchAbsenceImpactPreview(dateStart, dateEnd),
    enabled,
  });

  if (!enabled) return null;

  if (query.isLoading) {
    return (
      <Box sx={{ display: "flex", alignItems: "center", gap: 1, py: 1 }}>
        <CircularProgress size={14} />
        <Typography sx={{ fontSize: 12.5, color: GRAY_500 }}>Vérification du planning…</Typography>
      </Box>
    );
  }

  if (query.isError) return null; // never block the form on a preview failure

  const missions = query.data ?? [];

  if (missions.length === 0) {
    return (
      <Box sx={{ background: GREEN_50, borderRadius: "12px", px: 1.75, py: 1.25 }}>
        <Typography sx={{ fontSize: 13, fontWeight: 600, color: GREEN_700 }}>
          ✓ Aucune mission prévue pendant cette absence.
        </Typography>
      </Box>
    );
  }

  return (
    <Box sx={{ background: AMBER_50, borderRadius: "12px", px: 1.75, py: 1.25 }}>
      <Typography sx={{ fontSize: 13, fontWeight: 700, color: AMBER_700, mb: 1 }}>
        ⚠ {missions.length} mission{missions.length > 1 ? "s" : ""} {missions.length > 1 ? "sont" : "est"} déjà
        planifiée{missions.length > 1 ? "s" : ""} pendant cette période
      </Typography>
      <Stack spacing={0.75}>
        {missions.map((m) => (
          <Box key={m.missionId} sx={{ background: "#fff", borderRadius: "10px", px: 1.25, py: 1 }}>
            <Typography sx={{ fontSize: 12.5, fontWeight: 700, color: GRAY_900 }}>
              {dayjs(m.startAt).format("D MMM")} · {m.siteName ?? "—"}
            </Typography>
            <Typography sx={{ fontSize: 12, color: GRAY_600, mt: 0.25 }}>
              {formatTime(m.startAt)}–{formatTime(m.endAt)} · {m.counterpart ? m.counterpart.name : "À couvrir"}
            </Typography>
          </Box>
        ))}
      </Stack>
    </Box>
  );
}
