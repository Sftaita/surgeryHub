import { Box, Stack, Typography } from "@mui/material";
import type { SurgeonActivityIntervention } from "../api/surgeonActivity.types";

const GREEN_700 = "#2C7D5F";
const GRAY_900 = "#16202B";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

export const MEDALS = ["🥇", "🥈", "🥉"];

/**
 * Podium personnel (§10, Lot 4, D-098) — jamais un classement inter-chirurgiens : uniquement
 * les types d'intervention les plus réalisés par le chirurgien connecté. Composant partagé
 * entre SurgeonActivityPage (top 3 complet) et SurgeonHomePage (même rendu, même données —
 * voir useSurgeonActivity). `interventions` est déjà triée par le backend ; on ne fait que
 * `slice(0, 3)` ici (§5) — si moins de 3 types existent, seuls ceux disponibles s'affichent.
 * `showHeading` est désactivé sur Home (§12), qui fournit son propre en-tête "MES
 * INTERVENTIONS — <année>" et ne veut pas du second eyebrow "TOP INTERVENTIONS" en double.
 */
export function PodiumRows({ interventions, showHeading = true }: { interventions: SurgeonActivityIntervention[]; showHeading?: boolean }) {
  const top = interventions.slice(0, 3);
  if (top.length === 0) return null;

  return (
    <Stack spacing={1.25}>
      {showHeading && (
        <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700 }}>
          TOP INTERVENTIONS
        </Typography>
      )}
      <Stack spacing={1}>
        {top.map((it, i) => (
          <Box
            key={it.interventionTypeId ?? `legacy:${it.label}`}
            sx={{
              background: "#fff", borderRadius: "14px", boxShadow: SHADOW_XS, px: 2, py: 1.25,
              display: "flex", alignItems: "center", justifyContent: "space-between",
            }}
          >
            <Stack direction="row" alignItems="center" spacing={1.25} sx={{ minWidth: 0 }}>
              <Typography sx={{ fontSize: 20, flexShrink: 0 }}>{MEDALS[i]}</Typography>
              <Typography sx={{ fontSize: 14, fontWeight: 700, color: GRAY_900 }} noWrap title={it.label}>
                {it.label}
              </Typography>
            </Stack>
            <Typography sx={{ fontSize: 16, fontWeight: 800, color: GREEN_700, flexShrink: 0, pl: 1.5 }}>
              {it.count}
            </Typography>
          </Box>
        ))}
      </Stack>
    </Stack>
  );
}
