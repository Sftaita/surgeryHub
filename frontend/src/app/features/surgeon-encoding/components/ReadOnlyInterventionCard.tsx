import { Box, Chip, Stack, Typography } from "@mui/material";
import type { MissionEncodingEntry } from "../../encoding/api/encoding.types";
import { ReadOnlyMaterialList } from "./ReadOnlyMaterialList";

const GRAY_500 = "#727E8C";
const GRAY_900 = "#16202B";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

function representativeLabel(value: boolean | null | undefined): string | null {
  if (value === true) return "Délégué présent";
  if (value === false) return "Délégué absent";
  return null;
}

/**
 * Lot 6 (D-100) — une carte par entrée d'encodage (intervention réelle ou demande de
 * type en cours), en lecture seule. Aucun menu, aucun drag-and-drop, aucune mutation :
 * un composant distinct de InterventionsSection (édition instrumentiste), pas une
 * variante masquée de celui-ci (spec Lot 6 §8-9).
 */
export function ReadOnlyInterventionCard({ entry }: { entry: MissionEncodingEntry }) {
  const isDraft = entry.kind === "DRAFT";
  const firmName = entry.firm?.name ?? (isDraft ? entry.requestedFirmNameSnapshot : null);
  const repLabel = entry.kind === "INTERVENTION" ? representativeLabel(entry.representativePresent) : null;

  return (
    <Box sx={{ background: "#fff", borderRadius: "16px", padding: "14px 16px", boxShadow: SHADOW_XS }}>
      <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={1} sx={{ mb: 1 }}>
        <Box sx={{ minWidth: 0 }}>
          <Typography sx={{ fontSize: 15, fontWeight: 700, color: GRAY_900 }} noWrap title={entry.label}>
            {entry.label}
          </Typography>
          {firmName && (
            <Typography sx={{ fontSize: 12.5, color: GRAY_500, mt: 0.25 }}>{firmName}</Typography>
          )}
        </Box>
        {isDraft && (
          <Chip
            label="Demande en cours"
            size="small"
            sx={{ fontSize: 11, height: 22, flexShrink: 0, bgcolor: "#FFF7E6", color: "#8A6100" }}
          />
        )}
      </Stack>

      {repLabel && (
        <Typography sx={{ fontSize: 12.5, color: GRAY_500, mb: 1 }}>{repLabel}</Typography>
      )}

      <ReadOnlyMaterialList lines={entry.materialLines} />
    </Box>
  );
}
