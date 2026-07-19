import * as React from "react";
import {
  Alert,
  Button,
  Chip,
  Stack,
  TextField,
  Typography,
} from "@mui/material";

export interface RateVersion {
  id: number;
  amount: string;
  currency: string;
  validFrom: string | null;
  validTo: string | null;
}

type Status = "active" | "future" | "expired";

function statusOf(v: RateVersion, today: string): Status {
  if (v.validFrom && v.validFrom > today) return "future";
  if (v.validTo && v.validTo < today) return "expired";
  return "active";
}

const STATUS_LABEL: Record<Status, string> = { active: "En vigueur", future: "Programmé", expired: "Expiré" };
const STATUS_COLOR: Record<Status, "success" | "info" | "default"> = { active: "success", future: "info", expired: "default" };

export function getActiveVersion(versions: RateVersion[], today = new Date().toISOString().slice(0, 10)): RateVersion | undefined {
  return versions.find((v) => statusOf(v, today) === "active");
}

interface Props {
  versions: RateVersion[];
  defaultCurrency?: string;
  onCreateFirst: (body: { amount: number; currency: string; validFrom: string | null; validTo: string | null }) => void;
  onReplaceActive: (id: number, body: { amount: number; currency: string; effectiveFrom: string }) => void;
  onEditFuture: (id: number, body: { amount?: number; currency?: string; validFrom?: string | null; validTo?: string | null }) => void;
  onCancelFuture: (id: number) => void;
  isSaving?: boolean;
}

/**
 * Gestion générique d'un historique de tarif versionné à plat (append-only, validFrom/validTo,
 * D-072 §7/§10) — partagée entre PricingRule (firmes) et InstrumentistRate. Un tarif "en vigueur"
 * ne se modifie jamais en place : il se REMPLACE à partir d'une date (ferme l'ancien, ouvre le
 * nouveau). Seuls les tarifs programmés dans le futur restent modifiables/annulables directement.
 */
export default function RateVersionManager({
  versions, defaultCurrency = "EUR", onCreateFirst, onReplaceActive, onEditFuture, onCancelFuture, isSaving,
}: Props) {
  const today = new Date().toISOString().slice(0, 10);
  const [mode, setMode] = React.useState<null | "create" | "replace" | { edit: number }>(null);
  const [amount, setAmount] = React.useState("");
  const [currency, setCurrency] = React.useState(defaultCurrency);
  const [effectiveFrom, setEffectiveFrom] = React.useState("");
  const [validFrom, setValidFrom] = React.useState("");
  const [validTo, setValidTo] = React.useState("");

  const sorted = [...versions].sort((a, b) => (b.validFrom ?? "").localeCompare(a.validFrom ?? ""));
  const active = sorted.find((v) => statusOf(v, today) === "active");
  const future = sorted.filter((v) => statusOf(v, today) === "future").sort((a, b) => (a.validFrom ?? "").localeCompare(b.validFrom ?? ""));
  const past = sorted.filter((v) => statusOf(v, today) === "expired");

  function openReplace() {
    setAmount(active?.amount ?? "");
    setCurrency(active?.currency ?? defaultCurrency);
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    setEffectiveFrom(tomorrow.toISOString().slice(0, 10));
    setMode("replace");
  }
  function openCreate() {
    setAmount("");
    setCurrency(defaultCurrency);
    setValidFrom("");
    setValidTo("");
    setMode("create");
  }
  function openEdit(v: RateVersion) {
    setAmount(v.amount);
    setCurrency(v.currency);
    setValidFrom(v.validFrom ?? "");
    setValidTo(v.validTo ?? "");
    setMode({ edit: v.id });
  }

  const editingId = typeof mode === "object" && mode !== null ? mode.edit : null;

  return (
    <Stack spacing={1.5}>
      {active ? (
        <Stack direction="row" alignItems="center" spacing={1.5} flexWrap="wrap" useFlexGap>
          <Chip size="small" label={STATUS_LABEL.active} color={STATUS_COLOR.active} />
          <Typography variant="body2">
            <strong>{Number(active.amount).toFixed(2)} {active.currency}</strong>
            {" "}— depuis {active.validFrom ?? "toujours"}
            {active.validTo ? ` jusqu'au ${active.validTo}` : ""}
          </Typography>
          <Button size="small" onClick={openReplace} disabled={isSaving}>Remplacer à partir d'une date</Button>
        </Stack>
      ) : (
        <Stack spacing={1}>
          <Typography variant="body2" color="text.secondary">Aucun tarif en vigueur actuellement.</Typography>
          <Button size="small" variant="outlined" onClick={openCreate} sx={{ alignSelf: "flex-start" }} disabled={isSaving}>
            Définir un tarif
          </Button>
        </Stack>
      )}

      {future.length > 0 && (
        <Stack spacing={0.75}>
          <Typography variant="caption" fontWeight={700} color="text.secondary">Tarifs programmés</Typography>
          {future.map((v) => (
            <Stack key={v.id} direction="row" alignItems="center" spacing={1} flexWrap="wrap" useFlexGap>
              <Chip size="small" label={STATUS_LABEL.future} color={STATUS_COLOR.future} variant="outlined" />
              <Typography variant="body2">
                {Number(v.amount).toFixed(2)} {v.currency} — à partir du {v.validFrom}{v.validTo ? ` jusqu'au ${v.validTo}` : ""}
              </Typography>
              <Button size="small" onClick={() => openEdit(v)} disabled={isSaving}>Modifier</Button>
              <Button size="small" color="error" onClick={() => onCancelFuture(v.id)} disabled={isSaving}>Annuler</Button>
            </Stack>
          ))}
        </Stack>
      )}

      {past.length > 0 && (
        <Stack spacing={0.5}>
          <Typography variant="caption" fontWeight={700} color="text.secondary">Historique</Typography>
          {past.map((v) => (
            <Typography key={v.id} variant="caption" color="text.secondary" display="block">
              {Number(v.amount).toFixed(2)} {v.currency} — {v.validFrom ?? "…"} → {v.validTo ?? "…"}
            </Typography>
          ))}
        </Stack>
      )}

      {mode === "replace" && (
        <Stack spacing={1} sx={{ p: 1.5, bgcolor: "grey.50", borderRadius: 1 }}>
          <Alert severity="info" sx={{ py: 0.5 }}>
            Le tarif actuel reste inchangé avant la date choisie ; le nouveau montant s'applique automatiquement à partir de cette date.
          </Alert>
          <Stack direction="row" spacing={1.5} flexWrap="wrap" useFlexGap>
            <TextField label="Nouveau montant" type="number" size="small" value={amount} onChange={(e) => setAmount(e.target.value)} inputProps={{ min: 0, step: "0.01" }} sx={{ width: 160 }} />
            <TextField label="Devise" size="small" value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} sx={{ width: 90 }} />
            <TextField label="À partir du" type="date" size="small" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} InputLabelProps={{ shrink: true }} />
          </Stack>
          <Stack direction="row" spacing={1}>
            <Button size="small" onClick={() => setMode(null)}>Annuler</Button>
            <Button
              size="small" variant="contained" disableElevation
              disabled={!amount || Number(amount) < 0 || !effectiveFrom || isSaving}
              onClick={() => { if (active) { onReplaceActive(active.id, { amount: Number(amount), currency, effectiveFrom }); setMode(null); } }}
            >
              Valider le remplacement
            </Button>
          </Stack>
        </Stack>
      )}

      {mode === "create" && (
        <Stack spacing={1} sx={{ p: 1.5, bgcolor: "grey.50", borderRadius: 1 }}>
          <Stack direction="row" spacing={1.5} flexWrap="wrap" useFlexGap>
            <TextField label="Montant" type="number" size="small" value={amount} onChange={(e) => setAmount(e.target.value)} inputProps={{ min: 0, step: "0.01" }} sx={{ width: 160 }} />
            <TextField label="Devise" size="small" value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} sx={{ width: 90 }} />
            <TextField label="Valide à partir de" type="date" size="small" value={validFrom} onChange={(e) => setValidFrom(e.target.value)} InputLabelProps={{ shrink: true }} helperText="Vide = dès aujourd'hui" />
            <TextField label="Valide jusqu'à" type="date" size="small" value={validTo} onChange={(e) => setValidTo(e.target.value)} InputLabelProps={{ shrink: true }} helperText="Vide = sans fin" />
          </Stack>
          <Stack direction="row" spacing={1}>
            <Button size="small" onClick={() => setMode(null)}>Annuler</Button>
            <Button
              size="small" variant="contained" disableElevation
              disabled={!amount || Number(amount) < 0 || isSaving}
              onClick={() => { onCreateFirst({ amount: Number(amount), currency, validFrom: validFrom || null, validTo: validTo || null }); setMode(null); }}
            >
              Créer
            </Button>
          </Stack>
        </Stack>
      )}

      {editingId !== null && (
        <Stack spacing={1} sx={{ p: 1.5, bgcolor: "grey.50", borderRadius: 1 }}>
          <Stack direction="row" spacing={1.5} flexWrap="wrap" useFlexGap>
            <TextField label="Montant" type="number" size="small" value={amount} onChange={(e) => setAmount(e.target.value)} inputProps={{ min: 0, step: "0.01" }} sx={{ width: 160 }} />
            <TextField label="Devise" size="small" value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} sx={{ width: 90 }} />
            <TextField label="Valide à partir de" type="date" size="small" value={validFrom} onChange={(e) => setValidFrom(e.target.value)} InputLabelProps={{ shrink: true }} />
            <TextField label="Valide jusqu'à" type="date" size="small" value={validTo} onChange={(e) => setValidTo(e.target.value)} InputLabelProps={{ shrink: true }} helperText="Vide = sans fin" />
          </Stack>
          <Stack direction="row" spacing={1}>
            <Button size="small" onClick={() => setMode(null)}>Annuler</Button>
            <Button
              size="small" variant="contained" disableElevation
              disabled={!amount || Number(amount) < 0 || isSaving}
              onClick={() => { onEditFuture(editingId, { amount: Number(amount), currency, validFrom: validFrom || null, validTo: validTo || null }); setMode(null); }}
            >
              Enregistrer
            </Button>
          </Stack>
        </Stack>
      )}
    </Stack>
  );
}
