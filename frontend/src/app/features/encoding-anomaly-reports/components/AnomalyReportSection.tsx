import * as React from "react";
import { Box, Stack, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import dayjs from "dayjs";

import { fetchEncodingAnomalyReports } from "../api/encodingAnomalyReports.api";
import { ENCODING_ANOMALY_REPORT_TYPE_LABELS } from "../api/encodingAnomalyReports.types";
import { AnomalyReportSheet } from "./AnomalyReportSheet";

const GRAY_500 = "#727E8C";
const GRAY_900 = "#16202B";
const GREEN_700 = "#2C7D5F";
const AMBER_700 = "#8A6100";
const AMBER_50 = "#FFF7E6";
const AMBER_100 = "#FFEAB8";
const GREEN_50 = "#EFFAF5";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

/**
 * Lot 6 (D-100, §13-14, §22) — statut du dernier signalement + CTA "Signaler un
 * problème". Un signalement OPEN masque le CTA (évite un doublon accidentel, §14 déjà
 * appliqué côté backend mais reflété ici pour un retour visuel immédiat). Jamais une
 * grosse carte sur la Home — ce module vit uniquement sur l'écran d'encodage.
 */
export function AnomalyReportSection({ missionId }: { missionId: number }) {
  const [sheetOpen, setSheetOpen] = React.useState(false);

  const { data: reports } = useQuery({
    queryKey: ["encodingAnomalyReports", missionId],
    queryFn: () => fetchEncodingAnomalyReports(missionId),
    enabled: Number.isFinite(missionId) && missionId > 0,
  });

  const latest = reports?.[0] ?? null;
  const isOpen = latest?.status === "OPEN";

  return (
    <Box sx={{ background: "#fff", borderRadius: "16px", padding: "14px 16px", boxShadow: SHADOW_XS }}>
      {latest && (
        <Box
          sx={{
            borderRadius: "12px",
            padding: "10px 12px",
            mb: isOpen ? 0 : 1.5,
            background: isOpen ? AMBER_50 : GREEN_50,
            border: `1px solid ${isOpen ? AMBER_100 : "transparent"}`,
          }}
        >
          <Typography sx={{ fontSize: 13, fontWeight: 700, color: isOpen ? AMBER_700 : GREEN_700 }}>
            {isOpen ? "Problème signalé — en attente de traitement" : "Problème traité"}
          </Typography>
          <Typography sx={{ fontSize: 12, color: GRAY_500, mt: 0.25 }}>
            {ENCODING_ANOMALY_REPORT_TYPE_LABELS[latest.type]} · {dayjs(latest.createdAt).format("D MMM YYYY")}
          </Typography>
          {!isOpen && latest.resolutionComment && (
            <Typography sx={{ fontSize: 12.5, color: GRAY_900, mt: 0.5, fontStyle: "italic" }}>
              « {latest.resolutionComment} »
            </Typography>
          )}
        </Box>
      )}

      {!isOpen && (
        <Stack direction="row" alignItems="center" justifyContent="space-between">
          <Typography sx={{ fontSize: 13, color: GRAY_500 }}>
            Un problème sur cet encodage ?
          </Typography>
          <Box
            component="button"
            type="button"
            onClick={() => setSheetOpen(true)}
            sx={{
              border: "none", background: "none", cursor: "pointer", fontFamily: "inherit",
              fontSize: 13, fontWeight: 700, color: GREEN_700, padding: 0,
            }}
          >
            Signaler un problème
          </Box>
        </Stack>
      )}

      <AnomalyReportSheet open={sheetOpen} onClose={() => setSheetOpen(false)} missionId={missionId} />
    </Box>
  );
}
