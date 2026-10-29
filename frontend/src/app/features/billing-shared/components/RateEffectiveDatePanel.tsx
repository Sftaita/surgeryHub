import * as React from "react";
import { Alert, Button, Link, Stack, TextField, Typography } from "@mui/material";
import type { RateVersion } from "./RateVersionManager";

/** D-172 — calcul financier concerné par un changement de période tarifaire (servi par le backend). */
export interface RateImpactCalculationView {
  calculationId: number;
  missionId: number;
  missionDate: string;
  status: string;
}

export interface RateDateImpactView {
  period: { from: string; to: string };
  previousRate: { amount: string; currency: string; validFrom: string | null } | null;
  lockedCalculations: RateImpactCalculationView[];
  staleCalculations: RateImpactCalculationView[];
}

const CALCULATION_STATUS: Record<string, string> = { CALCULATED: "calculé", APPROVED: "approuvé", LOCKED: "verrouillé" };

function dmy(iso: string): string {
  const [y, m, d] = iso.slice(0, 10).split("-");
  return `${d}/${m}/${y}`;
}

/** Veille d'une date ISO (validTo est exclusif : le dernier jour couvert est la veille). */
function dayBefore(iso: string): string {
  const d = new Date(`${iso.slice(0, 10)}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() - 1);
  return d.toISOString().slice(0, 10);
}

export function ImpactedMissions({ items, testId }: { items: RateImpactCalculationView[]; testId: string }) {
  return (
    <Stack component="ul" spacing={0.25} sx={{ m: 0, pl: 2.5 }} data-testid={testId}>
      {items.map((c) => (
        <Typography component="li" variant="caption" key={c.calculationId}>
          <Link href={`/app/m/missions?mission=${c.missionId}`} underline="hover">Mission #{c.missionId}</Link>
          {" "}du {dmy(c.missionDate)} — calcul {CALCULATION_STATUS[c.status] ?? c.status}
        </Typography>
      ))}
    </Stack>
  );
}

interface Props {
  version: RateVersion;
  onCheck: (validFrom: string) => Promise<RateDateImpactView>;
  onConfirm: (validFrom: string) => Promise<RateDateImpactView>;
  onClose: () => void;
}

/**
 * D-172 — modifier la date de prise d'effet d'une version tarifaire, rétroactive comprise.
 * Toujours en deux temps : l'impact calculé par le backend (période dont le tarif change,
 * calculs verrouillés qui bloquent, calculs non verrouillés à recalculer), puis la confirmation.
 * Aucune règle n'est recalculée ici.
 */
export default function RateEffectiveDatePanel({ version, onCheck, onConfirm, onClose }: Props) {
  const [validFrom, setValidFrom] = React.useState(version.validFrom ?? "");
  const [impact, setImpact] = React.useState<RateDateImpactView | null>(null);
  const [checkedDate, setCheckedDate] = React.useState<string | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);
  const [done, setDone] = React.useState<RateDateImpactView | null>(null);

  const run = async (fn: () => Promise<RateDateImpactView>, after: (r: RateDateImpactView) => void) => {
    setBusy(true);
    setError(null);
    try {
      after(await fn());
    } catch (e: any) {
      setError(e?.response?.data?.error?.message ?? e?.message ?? String(e));
    } finally {
      setBusy(false);
    }
  };

  const check = () => run(() => onCheck(validFrom), (r) => { setImpact(r); setCheckedDate(validFrom); });
  const confirm = () => run(() => onConfirm(validFrom), (r) => setDone(r));
  const upToDate = impact !== null && checkedDate === validFrom;

  if (done) {
    return (
      <Stack spacing={1} sx={{ p: 1.5, bgcolor: "grey.50", borderRadius: 1 }} data-testid="rate-effective-date-done">
        <Alert severity="success" sx={{ py: 0.5 }}>
          Ce tarif s'applique désormais à partir du {dmy(validFrom)}.
        </Alert>
        {done.staleCalculations.length > 0 && (
          <Alert severity="warning" sx={{ py: 0.5 }}>
            {done.staleCalculations.length} calcul(s) non verrouillé(s) utilisent encore l'ancien tarif. Ils ne sont jamais
            recalculés automatiquement : ouvrez chaque mission et utilisez « Recalculer ».
            <ImpactedMissions items={done.staleCalculations} testId="rate-effective-date-stale" />
          </Alert>
        )}
        <Button size="small" onClick={onClose} sx={{ alignSelf: "flex-start" }}>Fermer</Button>
      </Stack>
    );
  }

  return (
    <Stack spacing={1} sx={{ p: 1.5, bgcolor: "grey.50", borderRadius: 1 }} data-testid="rate-effective-date-panel">
      <Typography variant="body2">
        Date de prise d'effet de {Number(version.amount).toFixed(2)} {version.currency}. Une date passée est permise : les
        missions réalisées depuis cette date relèvent de ce tarif.
      </Typography>
      <TextField
        label="Applicable à partir du" type="date" size="small" value={validFrom}
        onChange={(e) => setValidFrom(e.target.value)} InputLabelProps={{ shrink: true }} sx={{ width: 220 }}
        inputProps={{ "data-testid": "rate-effective-date-input" }}
      />
      {error && <Alert severity="error" sx={{ py: 0.5 }} data-testid="rate-effective-date-error">{error}</Alert>}
      {upToDate && impact && (
        <Stack spacing={1} data-testid="rate-effective-date-impact">
          <Typography variant="caption" color="text.secondary">
            Période dont le tarif change : du {dmy(impact.period.from)} au {dmy(dayBefore(impact.period.to))} inclus.
            {impact.previousRate && ` Le tarif précédent (${Number(impact.previousRate.amount).toFixed(2)} ${impact.previousRate.currency}) s'arrêtera le ${dmy(dayBefore(validFrom))}.`}
          </Typography>
          {impact.lockedCalculations.length > 0 && (
            <Alert severity="error" sx={{ py: 0.5 }}>
              Modification impossible : des calculs verrouillés par une facture ou un relevé utilisent cette période. Leurs montants
              ne sont jamais modifiés ; une correction passe par la procédure de correction des documents émis.
              <ImpactedMissions items={impact.lockedCalculations} testId="rate-effective-date-locked" />
            </Alert>
          )}
          {impact.lockedCalculations.length === 0 && impact.staleCalculations.length > 0 && (
            <Alert severity="warning" sx={{ py: 0.5 }}>
              {impact.staleCalculations.length} calcul(s) non verrouillé(s) devront être recalculés (jamais automatiquement) :
              <ImpactedMissions items={impact.staleCalculations} testId="rate-effective-date-stale" />
            </Alert>
          )}
          {impact.lockedCalculations.length === 0 && impact.staleCalculations.length === 0 && (
            <Alert severity="info" sx={{ py: 0.5 }}>Aucun calcul financier existant n'est concerné.</Alert>
          )}
        </Stack>
      )}
      <Stack direction="row" spacing={1}>
        <Button size="small" onClick={onClose} disabled={busy}>Annuler</Button>
        {!upToDate ? (
          <Button size="small" variant="outlined" onClick={check} disabled={!validFrom || validFrom === version.validFrom || busy} data-testid="rate-effective-date-check">
            Vérifier l'impact
          </Button>
        ) : (
          <Button
            size="small" variant="contained" disableElevation onClick={confirm}
            disabled={busy || (impact?.lockedCalculations.length ?? 0) > 0} data-testid="rate-effective-date-confirm"
          >
            Confirmer la date d'effet
          </Button>
        )}
      </Stack>
    </Stack>
  );
}
