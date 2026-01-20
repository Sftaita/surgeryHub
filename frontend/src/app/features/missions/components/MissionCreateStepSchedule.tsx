import {
  Box,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
} from "@mui/material";
import type { SchedulePrecision } from "../api/missions.types";

type Props = {
  value: {
    schedulePrecision: SchedulePrecision;
    startLocal: string; // "YYYY-MM-DDTHH:mm"
    endLocal: string; // "YYYY-MM-DDTHH:mm"
  };
  onChange: (next: Partial<Props["value"]>) => void;
};

function sameDay(a: string, b: string): boolean {
  if (!a || !b) return true;
  // datetime-local => prefix "YYYY-MM-DD"
  return a.slice(0, 10) === b.slice(0, 10);
}

function toComparable(s: string): number | null {
  // datetime-local is ISO-like, Date.parse works reliably in modern browsers
  // but returns ms epoch; if invalid -> NaN
  if (!s) return null;
  const t = Date.parse(s);
  return Number.isFinite(t) ? t : null;
}

export default function MissionCreateStepSchedule(props: Props) {
  const { value, onChange } = props;

  const startT = toComparable(value.startLocal);
  const endT = toComparable(value.endLocal);

  const hasBoth = Boolean(value.startLocal) && Boolean(value.endLocal);
  const invalidOrder =
    hasBoth && startT !== null && endT !== null ? endT <= startT : false;

  const invalidDay = hasBoth
    ? !sameDay(value.startLocal, value.endLocal)
    : false;

  const startError = false; // on garde les erreurs sur "Fin" (plus naturel)
  const endError = invalidOrder || invalidDay;

  const endHelperText = invalidDay
    ? "La mission doit commencer et finir le même jour."
    : invalidOrder
    ? "La fin doit être strictement après le début."
    : undefined;

  function handleStartChange(nextStart: string) {
    const next: Partial<Props["value"]> = { startLocal: nextStart };

    // Si end est défini, on applique les règles
    if (value.endLocal) {
      // même jour
      if (!sameDay(nextStart, value.endLocal)) {
        // on force end au même jour que start, en conservant l'heure si possible
        const endTime = value.endLocal.slice(11, 16) || "00:00";
        next.endLocal = `${nextStart.slice(0, 10)}T${endTime}`;
      }

      // end > start
      const sT = toComparable(nextStart);
      const eT = toComparable(next.endLocal ?? value.endLocal);

      if (sT !== null && eT !== null && eT <= sT) {
        // correction simple : end = start + 60 minutes
        const dt = new Date(sT + 60 * 60 * 1000);
        const yyyy = dt.getFullYear();
        const mm = String(dt.getMonth() + 1).padStart(2, "0");
        const dd = String(dt.getDate()).padStart(2, "0");
        const hh = String(dt.getHours()).padStart(2, "0");
        const min = String(dt.getMinutes()).padStart(2, "0");
        next.endLocal = `${yyyy}-${mm}-${dd}T${hh}:${min}`;
      }
    }

    onChange(next);
  }

  function handleEndChange(nextEnd: string) {
    const next: Partial<Props["value"]> = { endLocal: nextEnd };

    if (value.startLocal) {
      // même jour
      if (!sameDay(value.startLocal, nextEnd)) {
        const endTime = nextEnd.slice(11, 16) || "00:00";
        next.endLocal = `${value.startLocal.slice(0, 10)}T${endTime}`;
      }

      // end > start (si start valide)
      const sT = toComparable(value.startLocal);
      const eT = toComparable(next.endLocal ?? nextEnd);

      if (sT !== null && eT !== null && eT <= sT) {
        // on ne bloque pas brutalement : on laisse, mais erreur affichée
        // (le wizard bloquera au "Continuer")
      }
    }

    onChange(next);
  }

  return (
    <Box>
      <Stack spacing={2}>
        <FormControl fullWidth>
          <InputLabel id="precision-label">Précision</InputLabel>
          <Select
            labelId="precision-label"
            label="Précision"
            value={value.schedulePrecision}
            onChange={(e) =>
              onChange({
                schedulePrecision: e.target.value as SchedulePrecision,
              })
            }
          >
            <MenuItem value="EXACT">Horaire exact</MenuItem>
            <MenuItem value="APPROXIMATE">
              Horaire estimé (à confirmer)
            </MenuItem>
          </Select>
        </FormControl>

        <TextField
          label="Début"
          type="datetime-local"
          value={value.startLocal}
          onChange={(e) => handleStartChange(e.target.value)}
          InputLabelProps={{ shrink: true }}
          fullWidth
          error={startError}
        />

        <TextField
          label="Fin"
          type="datetime-local"
          value={value.endLocal}
          onChange={(e) => handleEndChange(e.target.value)}
          InputLabelProps={{ shrink: true }}
          fullWidth
          error={endError}
          helperText={endHelperText}
        />
      </Stack>
    </Box>
  );
}
