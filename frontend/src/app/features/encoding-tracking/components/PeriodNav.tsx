import { Button, ButtonGroup, IconButton, Stack, Typography } from "@mui/material";
import ChevronLeftIcon from "@mui/icons-material/ChevronLeft";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";
import { formatPeriodLabel, periodForShortcut, shiftPeriod, type Period, type PeriodShortcut } from "../period";

interface Props {
  value: Period;
  onChange: (next: Period) => void;
}

const SHORTCUTS: { key: PeriodShortcut; label: string }[] = [
  { key: "today", label: "Aujourd'hui" },
  { key: "yesterday", label: "Hier" },
  { key: "last7Days", label: "7 jours" },
  { key: "thisMonth", label: "Ce mois" },
];

/**
 * Suivi des encodages (D-118) — navigation temporelle. Le changement de période
 * rafraîchit les données via React Query (le composant n'appelle rien lui-même) : il ne
 * fait que produire une nouvelle valeur de `Period`, jamais de bouton "Appliquer".
 */
export function PeriodNav({ value, onChange }: Props) {
  return (
    <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap" useFlexGap>
      <Stack direction="row" alignItems="center">
        <IconButton size="small" aria-label="Période précédente" onClick={() => onChange(shiftPeriod(value, -1))}>
          <ChevronLeftIcon fontSize="small" />
        </IconButton>
        <Typography variant="subtitle2" fontWeight={700} sx={{ minWidth: 140, textAlign: "center" }}>
          {formatPeriodLabel(value)}
        </Typography>
        <IconButton size="small" aria-label="Période suivante" onClick={() => onChange(shiftPeriod(value, 1))}>
          <ChevronRightIcon fontSize="small" />
        </IconButton>
      </Stack>

      <ButtonGroup size="small" variant="outlined">
        {SHORTCUTS.map((s) => (
          <Button key={s.key} onClick={() => onChange(periodForShortcut(s.key))}>
            {s.label}
          </Button>
        ))}
      </ButtonGroup>
    </Stack>
  );
}

export default PeriodNav;
