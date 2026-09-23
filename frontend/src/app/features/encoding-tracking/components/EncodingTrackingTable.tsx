import { Box, Button, CircularProgress, Stack, Typography } from "@mui/material";
import { useNavigate } from "react-router-dom";
import type { EncodingState, EncodingTrackingItem } from "../api/encodingTracking.api";
import { EFFECTIVE_SOURCE_LABEL, MISSION_TYPE_LABEL, formatMinutes } from "../encodingStateMeta";
import { EmptyState } from "../../../ui/EmptyState";
import { resolveApiAssetUrl } from "../../../api/apiAssetUrl";

const GRAY_950 = "#0B1320";
const GRAY_800 = "#2A3643";
const GRAY_700 = "#3A4754";
const GRAY_500 = "#727E8C";
const GRAY_400 = "#98A2AE";
const GRAY_300 = "#BBC2CB";
const GRAY_150 = "#E7EBEF";
const GRAY_100 = "#EEF1F4";
const GRAY_75 = "#F1F4F7";
const GRAY_50 = "#F5F7FA";
const GREEN_800 = "#1F6B4F";
const GREEN_600 = "#338F6E";
const GREEN_500 = "#42A882";
const GREEN_100 = "#DDF4EA";
const AMBER_700 = "#B7791F";
const AMBER_500 = "#F0A91B";
const AMBER_100 = "#FBEACB";
const BLUE_700 = "#1B5FD0";
const BLUE_50 = "#EDF4FF";
const RED_700 = "#C62F36";
const RED_50 = "#FDEEEE";
const SHADOW_SM = "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)";

/**
 * Habillage visuel des 7 EncodingState réels — DÉLIBÉRÉMENT distinct de
 * ENCODING_STATE_CONFIG (encodingStateMeta.ts, qui mappe TO_ENCODE sur le rouge MUI
 * "error"). La maquette validée (CLAUDE.md du design, règle 3) est explicite : le rouge
 * est réservé au retard/à l'anomalie, jamais à un état normal — "À encoder" doit être
 * ambre. Ce mapping ne remplace pas encodingStateMeta.ts (toujours utilisé ailleurs,
 * ex. filtres), il corrige uniquement l'affichage de cette table.
 */
const STATE_TONE: Record<EncodingState, { bg: string; fg: string }> = {
  UPCOMING: { bg: GRAY_75, fg: GRAY_400 },
  TO_ENCODE: { bg: AMBER_500, fg: GRAY_950 },
  IN_PROGRESS: { bg: AMBER_100, fg: AMBER_700 },
  SUBMITTED: { bg: BLUE_50, fg: BLUE_700 },
  VALIDATED: { bg: GREEN_100, fg: GREEN_800 },
  LOCKED: { bg: GREEN_100, fg: GREEN_800 },
  NOT_APPLICABLE: { bg: GRAY_100, fg: GRAY_500 },
};

function initials(name: string): string {
  return name.split(/[\s-]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase();
}

function formatTime(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleTimeString("fr-BE", { hour: "2-digit", minute: "2-digit" });
}

function dayKey(iso: string | null): string {
  return iso ? iso.slice(0, 10) : "—";
}

function dayLabel(key: string): string {
  if (key === "—") return "Date inconnue";
  const d = new Date(`${key}T00:00:00`);
  const label = d.toLocaleDateString("fr-BE", { weekday: "long", day: "numeric", month: "long" });
  return label.charAt(0).toUpperCase() + label.slice(1);
}

function financeStyle(item: EncodingTrackingItem): string {
  if (item.financial.isBlocking) return RED_700;
  if (item.financial.state === "NOT_CALCULABLE") return GRAY_300;
  if (item.financial.state === "TO_CALCULATE") return BLUE_700;
  return GRAY_950;
}

interface Props {
  items: EncodingTrackingItem[];
  total: number;
  page: number;
  limit: number;
  isLoading: boolean;
  isError: boolean;
  onPageChange: (page: number) => void;
  emptyTitle: string;
  emptyDescription?: string;
  /** Ouvre le tiroir de détail. Absent (ex. usages historiques) : repli sur la navigation
   *  vers la page mission complète, comportement inchangé. */
  onOpen?: (missionId: number) => void;
  selectedMissionId?: number | null;
}

/**
 * Suivi des encodages (D-118) — table principale, regroupée par jour avec en-tête
 * collant (maquette validée). Une ligne = un item déjà entièrement résolu par le
 * backend ; ce composant n'affiche que ce qui est fourni, ne recalcule rien.
 */
export function EncodingTrackingTable({
  items, total, page, limit, isLoading, isError, onPageChange, emptyTitle, emptyDescription, onOpen, selectedMissionId,
}: Props) {
  const navigate = useNavigate();
  const totalPages = Math.max(1, Math.ceil(total / limit));

  if (isLoading) {
    return (
      <Box sx={{ p: 4, textAlign: "center" }}>
        <CircularProgress size={24} />
      </Box>
    );
  }

  if (isError) {
    return (
      <EmptyState
        title="Impossible de charger le suivi des encodages"
        description="Une erreur est survenue lors de la récupération des données. Réessayez dans un instant."
      />
    );
  }

  if (items.length === 0) {
    return <EmptyState title={emptyTitle} description={emptyDescription} />;
  }

  const order: string[] = [];
  const byDay = new Map<string, EncodingTrackingItem[]>();
  for (const item of items) {
    const key = dayKey(item.startAt);
    const list = byDay.get(key);
    if (list) list.push(item); else { byDay.set(key, [item]); order.push(key); }
  }
  order.sort();

  return (
    <Box sx={{ borderRadius: "16px", border: "1px solid", borderColor: GRAY_150, background: "#fff", boxShadow: SHADOW_SM, overflow: "hidden" }}>
      <Box sx={{
        display: "flex", alignItems: "center", gap: "14px", padding: "0 18px", height: 40, background: GRAY_75,
        borderBottom: "1px solid", borderColor: GRAY_150, fontSize: 11.5, fontWeight: 800, letterSpacing: ".06em", color: GRAY_500,
      }}>
        <Box sx={{ width: 52, flexShrink: 0 }}>HEURE</Box>
        <Box sx={{ width: 172, flexShrink: 0 }}>INSTRUMENTISTE</Box>
        <Box sx={{ flex: 1, minWidth: 0 }}>CHIRURGIEN · SITE</Box>
        <Box sx={{ width: 150, flexShrink: 0 }}>ENCODAGE</Box>
        <Box sx={{ width: 170, flexShrink: 0 }}>HEURES</Box>
        <Box sx={{ width: 110, flexShrink: 0, textAlign: "right" }}>FINANCE</Box>
        <Box sx={{ width: 26, flexShrink: 0 }} />
      </Box>

      <Box sx={{ maxHeight: 560, overflowY: "auto" }}>
        {order.map((key) => {
          const list = byDay.get(key)!;
          const late = list.filter((i) => i.encoding.isStale).length;
          const planned = Math.round(list.reduce((a, i) => a + i.hours.plannedMinutes, 0) / 60);
          return (
            <Box key={key}>
              <Box sx={{
                display: "flex", alignItems: "center", gap: "10px", padding: "0 18px", height: 34, background: GRAY_75,
                borderBottom: "1px solid", borderColor: GRAY_150, position: "sticky", top: 0, zIndex: 1,
              }}>
                <Box sx={{ fontSize: 12, fontWeight: 800, color: GRAY_800 }}>{dayLabel(key)}</Box>
                <Box sx={{ fontSize: 11.5, fontWeight: 700, color: GRAY_500, fontVariantNumeric: "tabular-nums" }}>
                  {list.length} mission{list.length > 1 ? "s" : ""}
                </Box>
                <Box sx={{ flex: 1 }} />
                {late > 0 && (
                  <Box sx={{ fontSize: 11.5, fontWeight: 800, color: RED_700 }}>{late} en retard</Box>
                )}
                <Box sx={{ fontSize: 11.5, fontWeight: 700, color: GRAY_500, fontVariantNumeric: "tabular-nums" }}>
                  {planned}h planifiées
                </Box>
              </Box>

              {list.map((item) => {
                const tone = STATE_TONE[item.encodingState];
                const selected = selectedMissionId === item.missionId;
                const pct = item.hours.plannedMinutes > 0
                  ? Math.min(100, Math.round((item.hours.effectiveMinutes / item.hours.plannedMinutes) * 100))
                  : 0;

                return (
                  <Box
                    key={item.missionId}
                    component="button"
                    type="button"
                    onClick={() => (onOpen ? onOpen(item.missionId) : navigate(`/app/m/missions/${item.missionId}`))}
                    sx={{
                      display: "flex", alignItems: "center", gap: "14px", width: "100%", padding: "0 18px", height: 72,
                      border: 0, borderBottom: "1px solid", borderColor: GRAY_150,
                      background: selected ? GREEN_100 : "#fff", boxShadow: selected ? `inset 3px 0 0 ${GREEN_600}` : undefined,
                      fontFamily: "inherit", textAlign: "left", cursor: "pointer",
                      "&:hover": { background: selected ? GREEN_100 : GRAY_50 },
                    }}
                  >
                    <Box sx={{ width: 52, flexShrink: 0, fontSize: 14, fontWeight: 800, color: GRAY_950, fontVariantNumeric: "tabular-nums" }}>
                      {formatTime(item.startAt)}
                    </Box>
                    <Box sx={{ width: 172, flexShrink: 0, display: "flex", alignItems: "center", gap: "9px", minWidth: 0 }}>
                      <Box sx={{
                        width: 30, height: 30, flexShrink: 0, borderRadius: "999px", background: GRAY_100, color: GRAY_700,
                        display: "grid", placeItems: "center", fontSize: 11, fontWeight: 800, overflow: "hidden",
                      }}>
                        {item.instrumentist?.photoPath
                          ? <Box component="img" src={resolveApiAssetUrl(item.instrumentist.photoPath)} alt="" sx={{ width: "100%", height: "100%", objectFit: "cover" }} />
                          : item.instrumentist ? initials(item.instrumentist.name ?? "—") : "—"}
                      </Box>
                      <Box sx={{ fontSize: 13.5, fontWeight: 700, color: GRAY_950, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                        {item.instrumentist?.name ?? "—"}
                      </Box>
                    </Box>
                    <Box sx={{ flex: 1, minWidth: 0 }}>
                      <Box sx={{ fontSize: 13.5, fontWeight: 600, color: GRAY_800, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                        {item.surgeon?.name ?? "—"}
                      </Box>
                      <Box sx={{ mt: "2px", fontSize: 12, color: GRAY_500, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                        {item.site?.name ?? "—"} · {item.missionType ? MISSION_TYPE_LABEL[item.missionType] : "—"}
                      </Box>
                    </Box>
                    <Box sx={{ width: 150, flexShrink: 0, display: "flex", alignItems: "center", gap: "6px", flexWrap: "nowrap" }}>
                      <Box sx={{
                        flexShrink: 0, display: "inline-flex", alignItems: "center", height: 24, padding: "0 10px",
                        borderRadius: "999px", fontSize: 11.5, fontWeight: 800, whiteSpace: "nowrap",
                        background: tone.bg, color: tone.fg,
                      }}>
                        {item.encodingStateLabel}
                      </Box>
                      {item.encoding.isStale && (
                        <Box sx={{
                          flexShrink: 0, display: "inline-flex", alignItems: "center", height: 24, padding: "0 8px",
                          borderRadius: "999px", fontSize: 11.5, fontWeight: 800, whiteSpace: "nowrap", background: RED_50, color: RED_700,
                        }}>
                          retard
                        </Box>
                      )}
                    </Box>
                    <Box sx={{ width: 170, flexShrink: 0 }}>
                      <Box sx={{ display: "flex", alignItems: "baseline", gap: "6px", fontVariantNumeric: "tabular-nums" }}>
                        {item.hours.hasRealHours ? (
                          <>
                            <Box sx={{ fontSize: 13.5, fontWeight: 800, color: GRAY_950 }}>{formatMinutes(item.hours.effectiveMinutes)}</Box>
                            <Box sx={{ fontSize: 12, color: GRAY_400 }}>/ {formatMinutes(item.hours.plannedMinutes)}</Box>
                          </>
                        ) : (
                          <Box sx={{ fontSize: 13.5, fontWeight: 800, color: GRAY_400 }}>{formatMinutes(item.hours.plannedMinutes)} planifiées</Box>
                        )}
                      </Box>
                      <Box sx={{ mt: "5px", height: 5, borderRadius: "99px", background: GRAY_150, overflow: "hidden" }}>
                        <Box sx={{ height: "100%", borderRadius: "99px", width: `${pct}%`, background: item.hours.hasRealHours ? GREEN_500 : "transparent" }} />
                      </Box>
                      <Box sx={{ mt: "2px", fontSize: 10.5, color: GRAY_400 }}>{EFFECTIVE_SOURCE_LABEL[item.hours.effectiveSource]}</Box>
                    </Box>
                    <Box sx={{ width: 110, flexShrink: 0, textAlign: "right", fontSize: 13.5, fontWeight: 800, color: financeStyle(item) }}>
                      {item.financial.label}
                    </Box>
                    <Box sx={{ width: 26, flexShrink: 0, color: GRAY_300, display: "grid", placeItems: "center" }}>
                      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
                        <path d="m9 6 6 6-6 6" />
                      </svg>
                    </Box>
                  </Box>
                );
              })}
            </Box>
          );
        })}
      </Box>

      {totalPages > 1 && (
        <Stack direction="row" justifyContent="center" alignItems="center" spacing={2} sx={{ p: 1.5, borderTop: "1px solid", borderColor: GRAY_150 }}>
          <Button size="small" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>Précédent</Button>
          <Typography variant="body2">Page {page} / {totalPages}</Typography>
          <Button size="small" disabled={page >= totalPages} onClick={() => onPageChange(page + 1)}>Suivant</Button>
        </Stack>
      )}
    </Box>
  );
}

export default EncodingTrackingTable;
