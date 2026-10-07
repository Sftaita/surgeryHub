import { Box, Stack, Typography } from "@mui/material";
import type { EncodingEntryMaterialLine } from "../../encoding/api/encoding.types";

const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const GRAY_900 = "#16202B";
const GREEN_150 = "#E7EBEF";

/**
 * Quantité toujours explicite ("Qté 4"), jamais un couple brut "4 1". `item.unit` est un
 * libellé libre du catalogue (ex. "pièce", "kit") : affiché seulement s'il contient au moins
 * une lettre — une valeur purement numérique (ex. "1", saisie comme "unité = 1") n'est pas une
 * unité lisible et se confondait avec une seconde quantité. Le contrat quantity/unit est
 * inchangé : seule la présentation filtre ce libellé.
 */
export function formatMaterialQuantity(quantity: string, unit: string | null | undefined): string {
  const num = Number(quantity);
  const display = Number.isFinite(num) ? String(num) : quantity;
  const label = (unit ?? "").trim();
  return /\p{L}/u.test(label) ? `Qté ${display} · ${label}` : `Qté ${display}`;
}

/**
 * Lot 6 (D-100) — présentation strictement lecture du matériel encodé par
 * l'instrumentiste pour une intervention : jamais de handler de clic, jamais de bouton
 * modifier/supprimer, jamais d'état de formulaire. Le chirurgien consulte le matériel
 * utilisé, il ne l'édite jamais (voir spec Lot 6 §8-9).
 */
export function ReadOnlyMaterialList({ lines }: { lines: EncodingEntryMaterialLine[] }) {
  if (lines.length === 0) {
    return (
      <Typography sx={{ fontSize: 13, color: GRAY_500, fontStyle: "italic" }}>
        Aucun matériel encodé pour cette intervention.
      </Typography>
    );
  }

  return (
    <Stack spacing={1}>
      {lines.map((line, idx) => (
        <Box
          key={line.id}
          sx={{ pt: idx > 0 ? 1 : 0, borderTop: idx > 0 ? "1px dashed" : "none", borderColor: GREEN_150 }}
        >
          <Stack direction="row" justifyContent="space-between" alignItems="baseline" spacing={1}>
            <Typography sx={{ fontSize: 13.5, color: GRAY_900, fontWeight: 600, minWidth: 0 }} noWrap title={line.item.label}>
              {line.item.label}
            </Typography>
            <Typography sx={{ fontSize: 13, color: GRAY_700, flexShrink: 0, fontVariantNumeric: "tabular-nums" }}>
              {formatMaterialQuantity(line.quantity, line.item.unit)}
            </Typography>
          </Stack>
          <Typography sx={{ fontSize: 12, color: GRAY_500 }}>
            {line.item.firm.name} · Réf. {line.item.referenceCode}
          </Typography>
          {line.comment && (
            <Typography sx={{ fontSize: 12.5, color: GRAY_700, mt: 0.25, fontStyle: "italic" }}>
              {line.comment}
            </Typography>
          )}
        </Box>
      ))}
    </Stack>
  );
}
