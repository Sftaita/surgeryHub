import { Box, Button, FormControlLabel, MenuItem, Switch, TextField, ToggleButton, ToggleButtonGroup, Tooltip } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import ViewDayOutlinedIcon from "@mui/icons-material/ViewDayOutlined";
import TableRowsOutlinedIcon from "@mui/icons-material/TableRowsOutlined";

import { SearchBox } from "../../../ui/SearchBox";
import { fetchSites } from "../api/missions.api";
import { MISSIONS_PERIODS, type MissionsPeriod } from "../utils/missionsListPeriod";

export type MissionsView = "day" | "table";

export interface MissionsFiltersValue {
  search: string;
  period: MissionsPeriod;
  status?: string;
  type?: string;
  siteId?: number;
  view: MissionsView;
  /** D-172 — annulées masquées par défaut (un statut choisi explicitement prime). */
  showCancelled: boolean;
  /** D-172 — seulement les missions sans instrumentiste affectée. */
  withoutInstrumentist: boolean;
}

type Props = {
  value: MissionsFiltersValue;
  onChange: (patch: Partial<MissionsFiltersValue>) => void;
  /** « À valider » : le statut est imposé par l'onglet. */
  statusLocked?: boolean;
  onReset?: () => void;
};

const STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: "DRAFT", label: "Brouillon" },
  { value: "OPEN", label: "Publiée" },
  { value: "ASSIGNED", label: "Assignée" },
  { value: "IN_PROGRESS", label: "En cours" },
  { value: "SUBMITTED", label: "Soumise" },
  { value: "DECLARED", label: "À valider" },
  { value: "VALIDATED", label: "Validée" },
  { value: "CLOSED", label: "Clôturée" },
  { value: "REJECTED", label: "Rejetée" },
  { value: "CANCELLED", label: "Annulée" },
];

/**
 * D-147 — barre de filtres de la vue Missions manager : recherche libre (serveur, `q`),
 * période, statut, type, site et choix de vue (par jour / tableau). Contrôlée : l'état
 * vit dans l'URL de la page.
 */
export function MissionsFiltersBar({ value, onChange, statusLocked, onReset }: Props) {
  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites, staleTime: 5 * 60_000 });
  const sites = sitesQuery.data ?? [];

  return (
    <Box sx={{ display: "flex", flexDirection: "column", gap: 1.5, mb: 2 }}>
      <Box sx={{ display: "flex", gap: 1.5, flexWrap: "wrap", alignItems: "center" }}>
        <SearchBox
          value={value.search}
          onChange={(search) => onChange({ search })}
          placeholder="Chirurgien, instrumentiste, site, #id…"
          sx={{ flex: "1 1 280px", minWidth: 220 }}
        />

        <ToggleButtonGroup
          size="small"
          exclusive
          value={value.view}
          onChange={(_, v: MissionsView | null) => { if (v) onChange({ view: v }); }}
          aria-label="Affichage"
        >
          <ToggleButton value="day" aria-label="Par jour">
            <Tooltip title="Grouper par jour"><ViewDayOutlinedIcon fontSize="small" /></Tooltip>
          </ToggleButton>
          <ToggleButton value="table" aria-label="Tableau">
            <Tooltip title="Tableau"><TableRowsOutlinedIcon fontSize="small" /></Tooltip>
          </ToggleButton>
        </ToggleButtonGroup>
      </Box>

      <Box sx={{ display: "flex", gap: 1.5, flexWrap: "wrap", alignItems: "center" }}>
        <ToggleButtonGroup
          size="small"
          exclusive
          value={value.period}
          onChange={(_, v: MissionsPeriod | null) => { if (v) onChange({ period: v }); }}
          aria-label="Période"
          sx={{ flexWrap: "wrap", "& .MuiToggleButton-root": { textTransform: "none", px: 1.5 } }}
        >
          {MISSIONS_PERIODS.map((p) => (
            <ToggleButton key={p.value} value={p.value}>{p.label}</ToggleButton>
          ))}
        </ToggleButtonGroup>

        {!statusLocked && (
          <TextField
            select
            label="Statut"
            size="small"
            value={value.status ?? ""}
            onChange={(e) => onChange({ status: e.target.value || undefined })}
            sx={{ minWidth: 150 }}
          >
            <MenuItem value="">Tous les statuts</MenuItem>
            {STATUS_OPTIONS.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
          </TextField>
        )}

        <TextField
          select
          label="Type"
          size="small"
          value={value.type ?? ""}
          onChange={(e) => onChange({ type: e.target.value || undefined })}
          sx={{ minWidth: 150 }}
        >
          <MenuItem value="">Tous les types</MenuItem>
          <MenuItem value="BLOCK">Bloc opératoire</MenuItem>
          <MenuItem value="CONSULTATION">Consultation</MenuItem>
        </TextField>

        <TextField
          select
          label="Site"
          size="small"
          value={value.siteId ? String(value.siteId) : ""}
          onChange={(e) => onChange({ siteId: e.target.value ? Number(e.target.value) : undefined })}
          sx={{ minWidth: 180 }}
        >
          <MenuItem value="">Tous les sites</MenuItem>
          {sites.map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.name}</MenuItem>)}
        </TextField>

        <FormControlLabel
          control={<Switch size="small" checked={value.withoutInstrumentist} onChange={(e) => onChange({ withoutInstrumentist: e.target.checked })} />}
          label="Sans instrumentiste"
          data-testid="filter-without-instrumentist"
        />

        {!statusLocked && !value.status && (
          <FormControlLabel
            control={<Switch size="small" checked={value.showCancelled} onChange={(e) => onChange({ showCancelled: e.target.checked })} />}
            label="Afficher les missions annulées"
            data-testid="filter-show-cancelled"
          />
        )}

        {onReset && (
          <Button size="small" onClick={onReset} sx={{ textTransform: "none" }}>
            Réinitialiser
          </Button>
        )}
      </Box>
    </Box>
  );
}
