import * as React from "react";
import { ClickAwayListener, MenuItem, Popper, Select } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "../../../api/apiClient";
import { getSurgeons } from "../../manager-surgeons/api/surgeons.api";
import type { EncodingState, MissionType } from "../api/encodingTracking.api";
import { ENCODING_STATE_ORDER, ENCODING_STATE_CONFIG, MISSION_TYPE_LABEL } from "../encodingStateMeta";

const GRAY_950 = "#0B1320";
const GRAY_700 = "#3A4754";
const GRAY_500 = "#727E8C";
const GRAY_200 = "#DDE2E8";
const GRAY_150 = "#E7EBEF";
const GRAY_50 = "#F5F7FA";
const GREEN_800 = "#1F6B4F";
const GREEN_600 = "#338F6E";
const GREEN_100 = "#DDF4EA";
const GREEN_50 = "#EFFAF5";
const GREEN_200 = "#BCE9D6";

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

const selectSx = {
  height: 32, fontSize: 12.5, fontWeight: 700, borderRadius: "8px", background: "#fff",
  "& .MuiOutlinedInput-notchedOutline": { borderColor: GRAY_200 },
};

/**
 * Suivi des encodages (D-118) — filtres du cockpit, en popover (maquette validée). Un
 * filtre vide signifie "tous", jamais une valeur devinée. Chaque changement se répercute
 * immédiatement dans la query key du parent — pas de bouton "Appliquer" (même convention
 * que la version précédente, cf. StatFilterBar.tsx / D-077).
 *
 * Site/chirurgien/instrumentiste restent des listes déroulantes à sélection unique
 * (potentiellement des dizaines/centaines d'entrées) plutôt que des puces façon maquette
 * (pensée pour une poignée d'options illustratives) — seul l'état d'encodage (7 valeurs
 * fixes) reprend le style "grille de puces cochables" à l'identique.
 */
export default function EncodingTrackingFilterBar({ value, onChange }: Props) {
  const [open, setOpen] = React.useState(false);
  const anchorRef = React.useRef<HTMLButtonElement>(null);

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

  function toggleState(s: EncodingState) {
    set("encodingState", value.encodingState.includes(s)
      ? value.encodingState.filter((x) => x !== s)
      : [...value.encodingState, s]);
  }

  const siteName = (id: string) => sitesQuery.data?.find((s) => String(s.id) === id)?.name ?? id;
  const instrumentistName = (id: string) => instrumentistsQuery.data?.items.find((u) => String(u.id) === id)?.displayName ?? id;
  const surgeonName = (id: string) => surgeonsQuery.data?.items.find((u) => String(u.id) === id)?.displayName ?? id;

  const chips: { key: string; field: string; label: string; onRemove: () => void }[] = [];
  if (value.siteId) chips.push({ key: "site", field: "SITE", label: siteName(value.siteId), onRemove: () => set("siteId", "") });
  if (value.surgeonId) chips.push({ key: "surgeon", field: "CHIRURGIEN", label: surgeonName(value.surgeonId), onRemove: () => set("surgeonId", "") });
  if (value.instrumentistId) chips.push({ key: "instr", field: "INSTRUMENTISTE", label: instrumentistName(value.instrumentistId), onRemove: () => set("instrumentistId", "") });
  if (value.missionType) chips.push({ key: "type", field: "TYPE", label: MISSION_TYPE_LABEL[value.missionType], onRemove: () => set("missionType", "") });
  for (const s of value.encodingState) {
    chips.push({ key: `state-${s}`, field: "ÉTAT", label: ENCODING_STATE_CONFIG[s].label, onRemove: () => toggleState(s) });
  }

  const activeCount = chips.length;

  return (
    <>
      {chips.map((c) => (
        <button
          key={c.key}
          type="button"
          onClick={c.onRemove}
          style={{
            display: "flex", alignItems: "center", gap: 7, height: 32, padding: "0 9px 0 11px", borderRadius: 9,
            background: GREEN_50, border: `1px solid ${GREEN_200}`, color: GREEN_800, font: "inherit",
            fontSize: 12.5, fontWeight: 700, cursor: "pointer", flexShrink: 0,
          }}
        >
          <span style={{ color: GREEN_600, fontWeight: 600 }}>{c.field}</span> {c.label}
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
            <path d="M18 6 6 18M6 6l12 12" />
          </svg>
        </button>
      ))}

      <button
        ref={anchorRef}
        type="button"
        onClick={() => setOpen((v) => !v)}
        style={{
          display: "inline-flex", alignItems: "center", gap: 8, height: 32, padding: "0 12px", borderRadius: 9,
          border: `1px solid ${GRAY_200}`, background: "#fff", color: GRAY_700, font: "inherit",
          fontSize: 12.5, fontWeight: 700, cursor: "pointer", flexShrink: 0,
        }}
      >
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M3 5h18M6 12h12M10 19h4" />
        </svg>
        Filtres{activeCount > 0 ? ` (${activeCount})` : ""}
      </button>

      <Popper open={open} anchorEl={anchorRef.current} placement="bottom-end" style={{ zIndex: 20 }}>
        <ClickAwayListener onClickAway={() => setOpen(false)}>
          <div style={{
            width: 420, background: "#fff", borderRadius: 14, border: `1px solid ${GRAY_200}`,
            boxShadow: "0 18px 44px -14px rgba(11,19,32,.35)", marginTop: 6,
          }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10, padding: "12px 14px", borderBottom: `1px solid ${GRAY_150}` }}>
              <span style={{ fontSize: 12.5, fontWeight: 800, color: GRAY_950 }}>Filtres</span>
              {activeCount > 0 && (
                <span style={{ display: "inline-flex", alignItems: "center", height: 19, padding: "0 7px", borderRadius: 999, background: GREEN_100, color: GREEN_800, fontSize: 11, fontWeight: 700 }}>
                  {activeCount} actif{activeCount > 1 ? "s" : ""}
                </span>
              )}
              <span style={{ flex: 1 }} />
              <button
                type="button"
                onClick={() => onChange(defaultTrackingFilterState())}
                style={{ border: 0, background: "none", font: "inherit", fontSize: 12, fontWeight: 700, color: GRAY_500, cursor: "pointer" }}
              >
                Tout effacer
              </button>
            </div>

            <div style={{ padding: "12px 14px", display: "grid", gridTemplateColumns: "1fr 1fr", gap: "14px 18px" }}>
              <FilterGroup label="SITE">
                <Select size="small" displayEmpty value={value.siteId} onChange={(e) => set("siteId", e.target.value)} sx={selectSx} fullWidth>
                  <MenuItem value="">Tous les sites</MenuItem>
                  {(sitesQuery.data ?? []).map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.name}</MenuItem>)}
                </Select>
              </FilterGroup>
              <FilterGroup label="CHIRURGIEN">
                <Select size="small" displayEmpty value={value.surgeonId} onChange={(e) => set("surgeonId", e.target.value)} sx={selectSx} fullWidth>
                  <MenuItem value="">Tous les chirurgiens</MenuItem>
                  {(surgeonsQuery.data?.items ?? []).map((u) => <MenuItem key={u.id} value={String(u.id)}>{u.displayName}</MenuItem>)}
                </Select>
              </FilterGroup>
              <FilterGroup label="INSTRUMENTISTE">
                <Select size="small" displayEmpty value={value.instrumentistId} onChange={(e) => set("instrumentistId", e.target.value)} sx={selectSx} fullWidth>
                  <MenuItem value="">Tous les instrumentistes</MenuItem>
                  {(instrumentistsQuery.data?.items ?? []).map((u) => <MenuItem key={u.id} value={String(u.id)}>{u.displayName}</MenuItem>)}
                </Select>
              </FilterGroup>
              <FilterGroup label="TYPE DE MISSION">
                <Select size="small" displayEmpty value={value.missionType} onChange={(e) => set("missionType", e.target.value as MissionType | "")} sx={selectSx} fullWidth>
                  <MenuItem value="">Tous types</MenuItem>
                  {(Object.keys(MISSION_TYPE_LABEL) as MissionType[]).map((t) => (
                    <MenuItem key={t} value={t}>{MISSION_TYPE_LABEL[t]}</MenuItem>
                  ))}
                </Select>
              </FilterGroup>
              <div style={{ gridColumn: "1 / -1" }}>
                <FilterGroup label="ÉTAT D'ENCODAGE">
                  <div style={{ display: "flex", flexWrap: "wrap", gap: 6 }}>
                    {ENCODING_STATE_ORDER.map((s) => {
                      const on = value.encodingState.includes(s);
                      return (
                        <button
                          key={s}
                          type="button"
                          onClick={() => toggleState(s)}
                          aria-pressed={on}
                          style={{
                            display: "flex", alignItems: "center", gap: 6, height: 28, padding: "0 10px", borderRadius: 8,
                            border: `1px solid ${on ? GREEN_600 : GRAY_200}`, background: on ? GREEN_50 : "#fff",
                            color: on ? GREEN_800 : GRAY_700, font: "inherit", fontSize: 12, fontWeight: 700, cursor: "pointer",
                          }}
                        >
                          {ENCODING_STATE_CONFIG[s].label}
                        </button>
                      );
                    })}
                  </div>
                </FilterGroup>
              </div>
            </div>

            <div style={{ display: "flex", alignItems: "center", gap: 10, padding: "11px 14px", borderTop: `1px solid ${GRAY_150}`, background: GRAY_50 }}>
              <span style={{ flex: 1, fontSize: 12, color: GRAY_500 }} />
              <button
                type="button"
                onClick={() => setOpen(false)}
                style={{
                  height: 34, padding: "0 14px", borderRadius: 9, border: `1px solid ${GRAY_200}`, background: "#fff",
                  color: GRAY_700, font: "inherit", fontSize: 12.5, fontWeight: 700, cursor: "pointer",
                }}
              >
                Fermer
              </button>
            </div>
          </div>
        </ClickAwayListener>
      </Popper>
    </>
  );
}

function FilterGroup({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 7 }}>
      <span style={{ fontSize: 11, fontWeight: 800, letterSpacing: ".09em", color: GRAY_500 }}>{label}</span>
      {children}
    </div>
  );
}
