import * as React from "react";
import { Box, ToggleButton, ToggleButtonGroup } from "@mui/material";
import type { EncodingEntryMaterialLine, MissionEncodingEntry } from "../../encoding/api/encoding.types";
import { formatMaterialQuantity } from "../../surgeon-encoding/components/ReadOnlyMaterialList";

const GRAY_950 = "#0B1320";
const GRAY_700 = "#3A4754";
const GRAY_600 = "#5A6675";
const GRAY_500 = "#727E8C";
const GRAY_400 = "#98A2AE";
const GRAY_200 = "#DDE2E8";
const GRAY_150 = "#E7EBEF";
const GRAY_50 = "#F5F7FA";
const GREEN_800 = "#1F6B4F";
const GREEN_100 = "#DDF4EA";
const AMBER_800 = "#8A6100";
const AMBER_50 = "#FFF7E6";
const RED_700 = "#C62F36";
const RED_50 = "#FDEEEE";

export type EncodingViewMode = "byIntervention" | "material" | "interventions";

const VIEW_LABELS: Record<EncodingViewMode, string> = {
  byIntervention: "Par intervention",
  material: "Matériel",
  interventions: "Interventions",
};

function representativeLabel(value: boolean | null | undefined): string | null {
  if (value === true) return "Délégué présent";
  if (value === false) return "Délégué absent";
  return null;
}

/**
 * D-138 — éléments visés par une anomalie financière, identifiés par le BACKEND
 * (missionInterventionId / materialLineId) : ce panneau ne fait que les repérer.
 * `interventionIds` ne contient que les anomalies portant sur l'intervention elle-même.
 */
export interface EncodingAnomalyMarks {
  interventionIds: ReadonlySet<number>;
  materialLineIds: ReadonlySet<number>;
}

/** Demande de navigation vers un élément ; `nonce` rejoue la même cible à chaque clic. */
export interface EncodingFocusTarget {
  missionInterventionId: number | null;
  materialLineId: number | null;
  nonce: number;
}

const NO_MARKS: EncodingAnomalyMarks = { interventionIds: new Set(), materialLineIds: new Set() };

const interventionAnchor = (id: number) => `itv-${id}`;
const materialAnchor = (id: number) => `ml-${id}`;

function firmNameOf(entry: MissionEncodingEntry): string | null {
  return entry.firm?.name ?? (entry.kind === "DRAFT" ? entry.requestedFirmNameSnapshot : null);
}

/**
 * Suivi des encodages — lecture de l'encodage Mission → Intervention → lignes de matériel
 * (D-136). Trois PROJECTIONS du même tableau `entries` de GET /api/missions/{id}/encoding,
 * dans l'ordre du backend : aucune requête, aucune donnée ni règle supplémentaire.
 * Strictement lecture seule (aucun handler de mutation), quel que soit le statut — la
 * validation verrouille l'écriture, jamais la consultation.
 *
 * D-138 — `anomalyMarks` signale les éléments en anomalie financière dans les trois modes ;
 * `focus` fait défiler jusqu'à l'élément visé et le met en évidence. Vue « Matériel » et
 * anomalie d'intervention : l'intervention n'y a pas de ligne propre, on bascule alors sur
 * « Par intervention ». Vue « Interventions » et anomalie de matériel : on vise
 * l'intervention qui porte la ligne.
 */
export function EncodingContentPanel({ entries, anomalyMarks = NO_MARKS, focus = null }: {
  entries: MissionEncodingEntry[];
  anomalyMarks?: EncodingAnomalyMarks;
  focus?: EncodingFocusTarget | null;
}) {
  const [mode, setMode] = React.useState<EncodingViewMode>("byIntervention");
  const [flashed, setFlashed] = React.useState<string | null>(null);
  const containerRef = React.useRef<HTMLDivElement | null>(null);
  const pendingFocus = React.useRef<EncodingFocusTarget | null>(null);

  React.useEffect(() => {
    if (!focus) return;
    pendingFocus.current = focus;
    if (mode === "material" && focus.materialLineId === null) {
      setMode("byIntervention"); // l'effet ci-dessous reprend après le rendu
    }
  }, [focus?.nonce]); // eslint-disable-line react-hooks/exhaustive-deps

  React.useEffect(() => {
    const target = pendingFocus.current;
    if (!target || (mode === "material" && target.materialLineId === null)) return;
    pendingFocus.current = null;
    const anchor = mode === "interventions" || target.materialLineId === null
      ? (target.missionInterventionId !== null ? interventionAnchor(target.missionInterventionId) : null)
      : materialAnchor(target.materialLineId);
    if (!anchor) return;
    const el = containerRef.current?.querySelector<HTMLElement>(`[data-anchor="${anchor}"]`);
    if (!el) return;
    el.scrollIntoView?.({ behavior: "smooth", block: "center" });
    setFlashed(anchor);
    const t = window.setTimeout(() => setFlashed(null), 2400);
    return () => window.clearTimeout(t);
  }, [focus?.nonce, mode]);

  return (
    <Box ref={containerRef} sx={{ display: "flex", flexDirection: "column", gap: "10px" }}>
      <ToggleButtonGroup
        size="small"
        exclusive
        value={mode}
        onChange={(_, next: EncodingViewMode | null) => { if (next) setMode(next); }}
        aria-label="Mode d'affichage de l'encodage"
        sx={{
          alignSelf: "flex-start",
          "& .MuiToggleButton-root": {
            textTransform: "none", fontSize: 12, fontWeight: 700, padding: "3px 10px", color: GRAY_600, borderColor: GRAY_200,
            "&.Mui-selected": { background: GREEN_100, color: GREEN_800, "&:hover": { background: GREEN_100 } },
          },
        }}
      >
        {(Object.keys(VIEW_LABELS) as EncodingViewMode[]).map((m) => (
          <ToggleButton key={m} value={m}>{VIEW_LABELS[m]}</ToggleButton>
        ))}
      </ToggleButtonGroup>

      {mode === "byIntervention" && entries.map((entry, idx) => (
        <InterventionBlock key={`${entry.kind}:${entry.id}`} entry={entry} index={idx + 1} total={entries.length} marks={anomalyMarks} flashed={flashed} />
      ))}
      {mode === "material" && <MaterialOnlyList entries={entries} marks={anomalyMarks} flashed={flashed} />}
      {mode === "interventions" && <InterventionsOnlyList entries={entries} marks={anomalyMarks} flashed={flashed} />}
    </Box>
  );
}

/** Une intervention = un bloc encadré et numéroté ; son matériel est en retrait dessous. */
function isMarkedIntervention(entry: MissionEncodingEntry, marks: EncodingAnomalyMarks): boolean {
  return entry.kind === "INTERVENTION" && marks.interventionIds.has(entry.id);
}

function flashSx(active: boolean) {
  return active ? { outline: `2px solid ${RED_700}`, outlineOffset: "2px" } : {};
}

function InterventionBlock({ entry, index, total, marks, flashed }: {
  entry: MissionEncodingEntry; index: number; total: number; marks: EncodingAnomalyMarks; flashed: string | null;
}) {
  const firmName = firmNameOf(entry);
  const anchor = entry.kind === "INTERVENTION" ? interventionAnchor(entry.id) : undefined;
  const marked = isMarkedIntervention(entry, marks);
  const repLabel = entry.kind === "INTERVENTION" ? representativeLabel(entry.representativePresent) : null;
  const requests = entry.materialItemRequests ?? [];

  return (
    <Box
      component="section"
      aria-label={`Intervention ${index} : ${entry.label}`}
      data-testid="intervention-block"
      data-anchor={anchor}
      data-anomaly={marked || undefined}
      sx={{ border: "1px solid", borderColor: marked ? RED_700 : GRAY_200, borderRadius: "12px", overflow: "hidden", background: "#fff", ...flashSx(!!anchor && flashed === anchor) }}
    >
      <Box sx={{ display: "flex", alignItems: "flex-start", gap: "10px", padding: "10px 12px", background: GRAY_50, borderBottom: "1px solid", borderColor: GRAY_150 }}>
        <Box sx={{
          flexShrink: 0, minWidth: 24, height: 24, padding: "0 6px", borderRadius: "7px", display: "grid", placeItems: "center",
          background: GRAY_950, color: "#fff", fontSize: 11.5, fontWeight: 800, fontVariantNumeric: "tabular-nums",
        }} aria-hidden>
          {index}
        </Box>
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Box sx={{ fontSize: 10.5, fontWeight: 800, letterSpacing: ".06em", color: GRAY_500 }}>
            INTERVENTION {index}/{total}
          </Box>
          <Box sx={{ fontSize: 14.5, fontWeight: 800, color: GRAY_950, overflowWrap: "anywhere" }}>{entry.label}</Box>
          {(firmName || repLabel) && (
            <Box sx={{ mt: "2px", fontSize: 12, color: GRAY_500 }}>
              {[firmName, repLabel].filter(Boolean).join(" · ")}
            </Box>
          )}
        </Box>
        {marked && <AnomalyChip />}
        {entry.kind === "DRAFT" && <DraftChip />}
      </Box>

      <Box sx={{ padding: "8px 12px 10px 12px" }}>
        {entry.materialLines.length === 0 && requests.length === 0 ? (
          <Box sx={{ fontSize: 12.5, color: GRAY_500, fontStyle: "italic", padding: "4px 0 2px 12px" }}>
            Aucun matériel encodé pour cette intervention.
          </Box>
        ) : (
          <Box sx={{ borderLeft: "3px solid", borderColor: GRAY_200, paddingLeft: "10px", display: "flex", flexDirection: "column" }}>
            {entry.materialLines.map((line, i) => (
              <MaterialRow key={line.id} line={line} first={i === 0} marks={marks} flashed={flashed} />
            ))}
            {requests.map((req, i) => (
              <Box key={`req-${req.id}`} sx={{ padding: "7px 0", borderTop: entry.materialLines.length + i > 0 ? "1px dashed" : "none", borderColor: GRAY_150 }}>
                <Box sx={{ display: "flex", alignItems: "baseline", gap: "8px" }}>
                  <Box sx={{ flex: 1, minWidth: 0, fontSize: 13, fontWeight: 700, color: GRAY_950, overflowWrap: "anywhere" }}>{req.label}</Box>
                  <Box sx={{ flexShrink: 0, fontSize: 11, fontWeight: 700, color: AMBER_800 }}>Hors catalogue</Box>
                </Box>
                {req.referenceCode && <Box sx={{ fontSize: 11.5, color: GRAY_500 }}>Réf. {req.referenceCode}</Box>}
              </Box>
            ))}
          </Box>
        )}
      </Box>
    </Box>
  );
}

function MaterialRow({ line, first, origin, marks, flashed }: {
  line: EncodingEntryMaterialLine; first: boolean; origin?: string; marks: EncodingAnomalyMarks; flashed: string | null;
}) {
  const anchor = materialAnchor(line.id);
  const marked = marks.materialLineIds.has(line.id);
  return (
    <Box
      data-testid="material-row"
      data-anchor={anchor}
      data-anomaly={marked || undefined}
      sx={{ padding: "7px 0", borderTop: first ? "none" : "1px dashed", borderColor: GRAY_150, ...(marked ? { background: RED_50, borderRadius: "4px" } : {}), ...flashSx(flashed === anchor) }}
    >
      <Box sx={{ display: "flex", alignItems: "baseline", gap: "8px" }}>
        <Box sx={{ flex: 1, minWidth: 0, fontSize: 13, fontWeight: 700, color: GRAY_950, overflowWrap: "anywhere" }}>{line.item.label}</Box>
        {marked && <AnomalyChip />}
        <Box sx={{ flexShrink: 0, fontSize: 12.5, fontWeight: 800, color: GRAY_700, fontVariantNumeric: "tabular-nums" }}>
          {formatMaterialQuantity(line.quantity, line.item.unit)}
        </Box>
      </Box>
      <Box sx={{ fontSize: 11.5, color: GRAY_500 }}>
        {line.item.firm.name}{line.item.referenceCode ? ` · Réf. ${line.item.referenceCode}` : ""}
      </Box>
      {line.comment && <Box sx={{ mt: "2px", fontSize: 12, color: GRAY_700, fontStyle: "italic" }}>{line.comment}</Box>}
      {origin && <Box sx={{ mt: "2px", fontSize: 11, color: GRAY_400 }}>{origin}</Box>}
    </Box>
  );
}

/** Vue "Matériel" : toutes les lignes, sans répéter les cartes d'intervention. */
function MaterialOnlyList({ entries, marks, flashed }: { entries: MissionEncodingEntry[]; marks: EncodingAnomalyMarks; flashed: string | null }) {
  const rows = entries.flatMap((entry, idx) =>
    entry.materialLines.map((line) => ({ line, origin: `↳ Intervention ${idx + 1} · ${entry.label}` })),
  );

  if (rows.length === 0) {
    return <Box sx={{ fontSize: 12.5, color: GRAY_500, fontStyle: "italic" }}>Aucun matériel encodé.</Box>;
  }

  return (
    <Box sx={{ border: "1px solid", borderColor: GRAY_200, borderRadius: "12px", padding: "4px 12px", background: "#fff" }}>
      {rows.map(({ line, origin }, i) => <MaterialRow key={line.id} line={line} first={i === 0} origin={origin} marks={marks} flashed={flashed} />)}
    </Box>
  );
}

/** Vue "Interventions" : la liste seule, sans leurs lignes de matériel. */
function InterventionsOnlyList({ entries, marks, flashed }: { entries: MissionEncodingEntry[]; marks: EncodingAnomalyMarks; flashed: string | null }) {
  return (
    <Box component="ol" sx={{ m: 0, p: 0, listStyle: "none", border: "1px solid", borderColor: GRAY_200, borderRadius: "12px", background: "#fff" }}>
      {entries.map((entry, idx) => {
        const firmName = firmNameOf(entry);
        const refs = entry.materialLines.length;
        const anchor = entry.kind === "INTERVENTION" ? interventionAnchor(entry.id) : undefined;
        // Vue sans matériel : une ligne de matériel en anomalie se signale sur son intervention.
        const marked = isMarkedIntervention(entry, marks) || entry.materialLines.some((l) => marks.materialLineIds.has(l.id));
        return (
          <Box
            component="li"
            key={`${entry.kind}:${entry.id}`}
            data-testid="intervention-row"
            data-anchor={anchor}
            data-anomaly={marked || undefined}
            sx={{ display: "flex", alignItems: "center", gap: "10px", padding: "9px 12px", borderTop: idx > 0 ? "1px solid" : "none", borderColor: GRAY_150, ...(marked ? { background: RED_50 } : {}), ...flashSx(!!anchor && flashed === anchor) }}
          >
            <Box sx={{ flexShrink: 0, width: 22, fontSize: 12, fontWeight: 800, color: GRAY_400, fontVariantNumeric: "tabular-nums" }}>{idx + 1}.</Box>
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Box sx={{ fontSize: 13.5, fontWeight: 700, color: GRAY_950, overflowWrap: "anywhere" }}>{entry.label}</Box>
              {firmName && <Box sx={{ fontSize: 11.5, color: GRAY_500 }}>{firmName}</Box>}
            </Box>
            {marked && <AnomalyChip />}
            {entry.kind === "DRAFT" && <DraftChip />}
            <Box sx={{ flexShrink: 0, fontSize: 11.5, color: GRAY_500, fontVariantNumeric: "tabular-nums" }}>
              {refs === 0 ? "aucun matériel" : `${refs} réf.`}
            </Box>
          </Box>
        );
      })}
    </Box>
  );
}

function AnomalyChip() {
  return (
    <Box sx={{ flexShrink: 0, height: 20, padding: "0 7px", borderRadius: "999px", display: "inline-flex", alignItems: "center", fontSize: 10.5, fontWeight: 800, background: RED_50, color: RED_700 }}>
      Anomalie
    </Box>
  );
}

function DraftChip() {
  return (
    <Box sx={{ flexShrink: 0, height: 22, padding: "0 8px", borderRadius: "999px", display: "inline-flex", alignItems: "center", fontSize: 11, fontWeight: 700, background: AMBER_50, color: AMBER_800 }}>
      Demande en cours
    </Box>
  );
}

export default EncodingContentPanel;
