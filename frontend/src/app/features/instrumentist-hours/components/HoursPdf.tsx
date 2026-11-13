import * as React from "react";
import { Button, CircularProgress, IconButton, Stack, Tooltip, Typography } from "@mui/material";
import PictureAsPdfOutlinedIcon from "@mui/icons-material/PictureAsPdfOutlined";
import FileDownloadOutlinedIcon from "@mui/icons-material/FileDownloadOutlined";
import type { PdfFile } from "../api/instrumentistHours.api";
import { HOURS_COLOR } from "./HoursUi";

// D-189 — téléchargement des PDF générés par le serveur (synthèse et décompte individuel).

function save({ blob, filename }: PdfFile) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function usePdf(onExport: () => Promise<PdfFile>) {
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState(false);
  const run = async () => {
    if (busy) return;
    setBusy(true);
    setError(false);
    try {
      save(await onExport());
    } catch {
      setError(true);
    } finally {
      setBusy(false);
    }
  };
  return { busy, error, run };
}

const outlinedSx = { color: HOURS_COLOR.green800, borderColor: HOURS_COLOR.gray200, whiteSpace: "nowrap", "&:hover": { borderColor: HOURS_COLOR.green700, bgcolor: HOURS_COLOR.green50 } } as const;

/** Bouton « Exporter PDF » : synthèse de la période et des filtres affichés. */
export function ExportPdfButton({ onExport, disabled }: { onExport: () => Promise<PdfFile>; disabled?: boolean }) {
  const { busy, error, run } = usePdf(onExport);
  return (
    <Stack direction="row" alignItems="center" gap={1}>
      {error && <Typography role="alert" sx={{ fontSize: 12.5, color: HOURS_COLOR.red700 }}>Export PDF impossible. Réessayez.</Typography>}
      <Button variant="outlined" onClick={() => void run()} disabled={disabled || busy} sx={outlinedSx}
        startIcon={busy ? <CircularProgress size={16} /> : <PictureAsPdfOutlinedIcon />}>
        {busy ? "Export…" : "Exporter PDF"}
      </Button>
    </Stack>
  );
}

/** « Télécharger le décompte PDF » d'une instrumentiste : icône (ligne du tableau) ou bouton libellé (tiroir). */
export function StatementPdfButton({ name, onExport, variant = "icon" }: { name: string; onExport: () => Promise<PdfFile>; variant?: "icon" | "button" }) {
  const { busy, error, run } = usePdf(onExport);
  if (variant === "button") {
    return (
      <Stack direction="row" alignItems="center" gap={1} flexWrap="wrap">
        <Button variant="outlined" size="small" onClick={() => void run()} disabled={busy} sx={outlinedSx}
          startIcon={busy ? <CircularProgress size={14} /> : <FileDownloadOutlinedIcon />}>
          {busy ? "Préparation…" : "Télécharger le décompte PDF"}
        </Button>
        {error && <Typography role="alert" sx={{ fontSize: 12.5, color: HOURS_COLOR.red700 }}>Téléchargement impossible. Réessayez.</Typography>}
      </Stack>
    );
  }
  return (
    <Tooltip title={error ? "Téléchargement impossible. Réessayez." : "Télécharger le décompte PDF"}>
      <span>
        <IconButton aria-label={`Télécharger le décompte PDF de ${name}`} disabled={busy} size="small"
          onClick={(e) => { e.stopPropagation(); void run(); }}
          onKeyDown={(e) => e.stopPropagation()}
          sx={{ color: error ? HOURS_COLOR.red700 : HOURS_COLOR.green800, border: `1px solid ${HOURS_COLOR.gray200}`, borderRadius: "10px", "&:hover": { bgcolor: HOURS_COLOR.green50 } }}>
          {busy ? <CircularProgress size={16} /> : <FileDownloadOutlinedIcon fontSize="small" />}
        </IconButton>
      </span>
    </Tooltip>
  );
}
