import { Checkbox, ListItemText, MenuItem, Paper, Select, Stack, type SelectChangeEvent } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "../../../api/apiClient";
import { getSurgeons } from "../../manager-surgeons/api/surgeons.api";
import type { EncodingState, MissionType } from "../api/encodingTracking.api";
import { ENCODING_STATE_ORDER, ENCODING_STATE_CONFIG, MISSION_TYPE_LABEL } from "../encodingStateMeta";

export interface TrackingFilterState {
  siteId: string;
  surgeonId: string;
  instrumentistId: string;
  missionType: MissionType | "";
  encodingState: EncodingState[];
}

export function defaultTrackingFilterState(): TrackingFilterState {
  return { siteId: "", surgeonId: "", instrumentistId: "", missionType: "", encodingState: [] };
}

interface Props {
  value: TrackingFilterState;
  onChange: (next: TrackingFilterState) => void;
}

/**
 * Suivi des encodages (D-118) — filtres du cockpit. Un filtre vide signifie "tous",
 * jamais une valeur devinée (même convention que StatFilterBar.tsx / D-077). Chaque
 * changement se répercute immédiatement dans la query key du parent — pas de bouton
 * "Appliquer".
 */
export default function EncodingTrackingFilterBar({ value, onChange }: Props) {
  const sitesQuery = useQuery({
    queryKey: ["stat-filter-sites"],
    queryFn: async () => (await apiClient.get("/api/sites")).data as { id: number; name: string }[],
  });
  const instrumentistsQuery = useQuery({
    queryKey: ["stat-filter-instrumentists"],
    queryFn: async () => (await apiClient.get("/api/instrumentists")).data as { items: { id: number; displayName: string }[] },
  });
  const surgeonsQuery = useQuery({
    queryKey: ["stat-filter-surgeons"],
    queryFn: () => getSurgeons(),
  });

  function set<K extends keyof TrackingFilterState>(key: K, v: TrackingFilterState[K]) {
    onChange({ ...value, [key]: v });
  }

  function handleEncodingStateChange(e: SelectChangeEvent<EncodingState[]>) {
    const raw = e.target.value;
    set("encodingState", typeof raw === "string" ? (raw.split(",") as EncodingState[]) : raw);
  }

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
      <Stack direction="row" spacing={1.5} flexWrap="wrap" useFlexGap alignItems="center">
        <Select size="small" displayEmpty value={value.siteId} onChange={(e) => set("siteId", e.target.value)} sx={{ minWidth: 150 }}>
          <MenuItem value="">Tous les sites</MenuItem>
          {(sitesQuery.data ?? []).map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.name}</MenuItem>)}
        </Select>
        <Select size="small" displayEmpty value={value.instrumentistId} onChange={(e) => set("instrumentistId", e.target.value)} sx={{ minWidth: 170 }}>
          <MenuItem value="">Tous les instrumentistes</MenuItem>
          {(instrumentistsQuery.data?.items ?? []).map((u) => <MenuItem key={u.id} value={String(u.id)}>{u.displayName}</MenuItem>)}
        </Select>
        <Select size="small" displayEmpty value={value.surgeonId} onChange={(e) => set("surgeonId", e.target.value)} sx={{ minWidth: 150 }}>
          <MenuItem value="">Tous les chirurgiens</MenuItem>
          {(surgeonsQuery.data?.items ?? []).map((u) => <MenuItem key={u.id} value={String(u.id)}>{u.displayName}</MenuItem>)}
        </Select>
        <Select
          size="small" displayEmpty value={value.missionType}
          onChange={(e) => set("missionType", e.target.value as MissionType | "")}
          sx={{ minWidth: 160 }}
        >
          <MenuItem value="">Tous types de mission</MenuItem>
          {(Object.keys(MISSION_TYPE_LABEL) as MissionType[]).map((t) => (
            <MenuItem key={t} value={t}>{MISSION_TYPE_LABEL[t]}</MenuItem>
          ))}
        </Select>
        <Select
          size="small" multiple displayEmpty value={value.encodingState}
          onChange={handleEncodingStateChange}
          renderValue={(selected) => (selected.length === 0 ? "Tous états d'encodage" : selected.map((s) => ENCODING_STATE_CONFIG[s].label).join(", "))}
          sx={{ minWidth: 200 }}
        >
          {ENCODING_STATE_ORDER.map((s) => (
            <MenuItem key={s} value={s}>
              <Checkbox size="small" checked={value.encodingState.includes(s)} />
              <ListItemText primary={ENCODING_STATE_CONFIG[s].label} />
            </MenuItem>
          ))}
        </Select>
      </Stack>
    </Paper>
  );
}
