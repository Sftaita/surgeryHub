import { Box, MenuItem, Paper, Select, Stack, TextField, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "../../../api/apiClient";
import { getSurgeons } from "../../manager-surgeons/api/surgeons.api";
import { getInterventionTypes } from "../../intervention-types/api/interventionTypes.api";
import type { StatisticsFilter } from "../api/financialStatistics.api";

function defaultFrom(): string {
  const d = new Date();
  d.setMonth(d.getMonth() - 1);
  d.setDate(1);
  return d.toISOString().slice(0, 10);
}

function defaultTo(): string {
  const d = new Date();
  d.setMonth(d.getMonth() + 1);
  d.setDate(1);
  return d.toISOString().slice(0, 10);
}

export interface FilterState {
  from: string;
  to: string;
  siteId: string;
  surgeonId: string;
  instrumentistId: string;
  firmId: string;
  interventionTypeId: string;
  currency: string;
}

export function defaultFilterState(): FilterState {
  return { from: defaultFrom(), to: defaultTo(), siteId: "", surgeonId: "", instrumentistId: "", firmId: "", interventionTypeId: "", currency: "" };
}

export function toApiFilter(f: FilterState): StatisticsFilter {
  return {
    from: f.from ? `${f.from}T00:00:00` : undefined,
    to: f.to ? `${f.to}T00:00:00` : undefined,
    siteId: f.siteId ? Number(f.siteId) : undefined,
    surgeonId: f.surgeonId ? Number(f.surgeonId) : undefined,
    instrumentistId: f.instrumentistId ? Number(f.instrumentistId) : undefined,
    firmId: f.firmId ? Number(f.firmId) : undefined,
    interventionTypeId: f.interventionTypeId ? Number(f.interventionTypeId) : undefined,
    currency: f.currency || undefined,
  };
}

interface Props {
  value: FilterState;
  onChange: (next: FilterState) => void;
}

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §6 du lot : filtres communs à tous les
 * endpoints statistiques. Un filtre vide signifie "tous", jamais une valeur devinée.
 * `from`/`to` par défaut : mois précédent → mois suivant (fenêtre large mais bornée),
 * jamais un chargement non borné par défaut.
 */
export default function StatFilterBar({ value, onChange }: Props) {
  const sitesQuery = useQuery({
    queryKey: ["stat-filter-sites"],
    queryFn: async () => (await apiClient.get("/api/sites")).data as { id: number; name: string }[],
  });
  const firmsQuery = useQuery({
    queryKey: ["stat-filter-firms"],
    queryFn: async () => (await apiClient.get("/api/firms")).data as { id: number; name: string }[],
  });
  const instrumentistsQuery = useQuery({
    queryKey: ["stat-filter-instrumentists"],
    queryFn: async () => (await apiClient.get("/api/instrumentists")).data as { items: { id: number; displayName: string }[] },
  });
  const surgeonsQuery = useQuery({
    queryKey: ["stat-filter-surgeons"],
    queryFn: () => getSurgeons(),
  });
  const typesQuery = useQuery({
    queryKey: ["stat-filter-intervention-types"],
    queryFn: () => getInterventionTypes(),
  });

  function set<K extends keyof FilterState>(key: K, v: FilterState[K]) {
    onChange({ ...value, [key]: v });
  }

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
      <Stack direction="row" spacing={1.5} flexWrap="wrap" useFlexGap alignItems="center">
        <TextField
          size="small" type="date" label="Du" value={value.from}
          onChange={(e) => set("from", e.target.value)}
          InputLabelProps={{ shrink: true }} sx={{ width: 160 }}
        />
        <TextField
          size="small" type="date" label="Au (exclusif)" value={value.to}
          onChange={(e) => set("to", e.target.value)}
          InputLabelProps={{ shrink: true }} sx={{ width: 160 }}
        />
        <Select size="small" displayEmpty value={value.siteId} onChange={(e) => set("siteId", e.target.value)} sx={{ minWidth: 150 }}>
          <MenuItem value="">Tous les sites</MenuItem>
          {(sitesQuery.data ?? []).map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.name}</MenuItem>)}
        </Select>
        <Select size="small" displayEmpty value={value.firmId} onChange={(e) => set("firmId", e.target.value)} sx={{ minWidth: 150 }}>
          <MenuItem value="">Toutes les firmes</MenuItem>
          {(firmsQuery.data ?? []).map((f) => <MenuItem key={f.id} value={String(f.id)}>{f.name}</MenuItem>)}
        </Select>
        <Select size="small" displayEmpty value={value.instrumentistId} onChange={(e) => set("instrumentistId", e.target.value)} sx={{ minWidth: 170 }}>
          <MenuItem value="">Tous les instrumentistes</MenuItem>
          {(instrumentistsQuery.data?.items ?? []).map((u) => <MenuItem key={u.id} value={String(u.id)}>{u.displayName}</MenuItem>)}
        </Select>
        <Select size="small" displayEmpty value={value.surgeonId} onChange={(e) => set("surgeonId", e.target.value)} sx={{ minWidth: 150 }}>
          <MenuItem value="">Tous les chirurgiens</MenuItem>
          {(surgeonsQuery.data?.items ?? []).map((u) => <MenuItem key={u.id} value={String(u.id)}>{u.displayName}</MenuItem>)}
        </Select>
        <Select size="small" displayEmpty value={value.interventionTypeId} onChange={(e) => set("interventionTypeId", e.target.value)} sx={{ minWidth: 160 }}>
          <MenuItem value="">Toutes interventions</MenuItem>
          {(typesQuery.data ?? []).map((t) => <MenuItem key={t.id} value={String(t.id)}>{t.code}</MenuItem>)}
        </Select>
        <TextField
          size="small" label="Devise" placeholder="EUR" value={value.currency}
          onChange={(e) => set("currency", e.target.value.toUpperCase())}
          sx={{ width: 100 }}
        />
        <Box sx={{ flex: 1 }} />
        <Typography variant="caption" color="text.secondary">
          Période : {value.from || "—"} → {value.to || "—"} (borne de fin exclusive)
        </Typography>
      </Stack>
    </Paper>
  );
}
