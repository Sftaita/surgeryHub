import * as React from "react";
import { Box, Button, Paper, Stack, Typography } from "@mui/material";
import PictureAsPdfOutlinedIcon from "@mui/icons-material/PictureAsPdfOutlined";

/**
 * D-186 — convention d'affichage commune aux statistiques financières (et au PDF serveur,
 * voir FinancialStatisticsPdfExporter::money()) :
 * - `revenue` : recette, « + » vert ;
 * - `expense` : dépense, « − » rouge — l'API renvoie les dépenses en magnitudes positives,
 *   le signe n'est donc appliqué qu'une seule fois, ici ;
 * - `net` : solde déjà calculé par le serveur, signe porté par la donnée, vert/rouge/neutre ;
 * - `amount` : montant neutre (solde ouvert, moyenne…).
 * Aucun calcul : uniquement de la mise en forme d'un montant reçu.
 */
export type AmountKind = "revenue" | "expense" | "net" | "amount";

const NBSP = " ";

export function formatSignedMoney(amount: string | number, kind: AmountKind, currency: string): string {
  const cents = Math.round(Number(amount) * 100);
  const abs = new Intl.NumberFormat("fr-BE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Math.abs(cents) / 100);
  const sign =
    kind === "revenue" ? (cents === 0 ? "" : "+")
    : kind === "expense" ? (cents === 0 ? "" : "−")
    : kind === "net" ? (cents > 0 ? "+" : cents < 0 ? "−" : "")
    : (cents < 0 ? "−" : "");
  return `${sign}${abs}${NBSP}${currency === "EUR" ? "€" : currency}`;
}

export function amountColor(amount: string | number, kind: AmountKind): string | undefined {
  const cents = Math.round(Number(amount) * 100);
  if (kind === "amount") return undefined;
  if (cents === 0) return "text.secondary";
  if (kind === "revenue") return "success.main";
  if (kind === "expense") return "error.main";
  return cents > 0 ? "success.main" : "error.main";
}

export function Amount({ amount, kind, currency, strong }: { amount: string | number; kind: AmountKind; currency: string; strong?: boolean }) {
  return (
    <Box component="span" sx={{ color: amountColor(amount, kind), fontWeight: strong ? 700 : 500, whiteSpace: "nowrap", fontVariantNumeric: "tabular-nums" }}>
      {formatSignedMoney(amount, kind, currency)}
    </Box>
  );
}

export interface SummaryCard {
  label: string;
  amount: string;
  kind: AmountKind;
}

/** Indicateurs au-dessus d'un tableau : un bloc par devise, jamais additionnées entre elles. */
export function FinancialSummary({ groups }: { groups: { currency: string; cards: SummaryCard[] }[] }) {
  if (groups.length === 0) return null;
  return (
    <Stack spacing={1.5}>
      {groups.map((g) => (
        <Box key={g.currency}>
          {groups.length > 1 && <Typography variant="caption" fontWeight={700} color="text.secondary">Devise : {g.currency}</Typography>}
          <Box sx={{ display: "grid", gridTemplateColumns: { xs: "1fr", sm: `repeat(${g.cards.length}, minmax(0, 1fr))` }, gap: 1.5, mt: 0.5 }}>
            {g.cards.map((c) => (
              <Paper key={c.label} variant="outlined" sx={{ p: 1.75, borderRadius: 2 }}>
                <Typography variant="caption" color="text.secondary">{c.label}</Typography>
                <Typography variant="h6" component="div" lineHeight={1.3}>
                  <Amount amount={c.amount} kind={c.kind} currency={g.currency} strong />
                </Typography>
              </Paper>
            ))}
          </Box>
        </Box>
      ))}
    </Stack>
  );
}

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

/** Bouton « Exporter en PDF » : le fichier est généré par le serveur (mêmes données que l'écran). */
export function ExportPdfButton({ onExport }: { onExport: () => Promise<{ blob: Blob; filename: string }> }) {
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  async function run() {
    setBusy(true);
    setError(null);
    try {
      const { blob, filename } = await onExport();
      downloadBlob(blob, filename);
    } catch {
      setError("Export PDF impossible. Réessayez.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Stack direction="row" spacing={1} alignItems="center">
      {error && <Typography variant="caption" color="error.main">{error}</Typography>}
      <Button size="small" variant="outlined" startIcon={<PictureAsPdfOutlinedIcon />} onClick={run} disabled={busy}>
        {busy ? "Export…" : "Exporter en PDF"}
      </Button>
    </Stack>
  );
}
