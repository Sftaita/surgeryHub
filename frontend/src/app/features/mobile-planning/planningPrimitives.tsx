import * as React from "react";
import { Box, Stack, Typography } from "@mui/material";
import { DateTile, type DateTileVariant } from "../../ui/mobile/DateTile";
import { StatusPill, type StatusPillVariant } from "../../ui/mobile/StatusPill";

/**
 * Primitives de planning mobile — extraites de `pages/instrumentist/PlanningPage.tsx`
 * (Lot 2, socle chirurgien D-095) pour être partagées avec le planning chirurgien sans
 * dupliquer le moteur de grille/calendrier. Aucun changement de comportement pour
 * l'instrumentiste : ce fichier est un déplacement, pas une réécriture — voir
 * PlanningPage.tsx qui importe désormais ces fonctions au lieu de les définir
 * localement (verrouillé par PlanningPage.test.tsx, non-régression explicite).
 *
 * Ce qui reste volontairement HORS de ce fichier (spécifique à chaque rôle, jamais
 * généralisé de force) : la classification "à encoder"/"à couvrir" d'une mission, le
 * libellé de la personne affichée sur une ligne (chirurgien pour l'instrumentiste,
 * instrumentiste pour le chirurgien), et la bannière "aucune mission à venir" (CTA
 * différent par rôle). MissionListRow ci-dessous reste un pur composant de
 * présentation : le rôle appelant calcule son `subtitle`/`statusInfo` et les passe en
 * props, plutôt que MissionListRow ne les recalcule lui-même.
 */

export type ViewMode = "week" | "month";

const GREEN_50 = "#EFFAF5";
const GREEN_300 = "#8FDABF";
const GREEN_500 = "#42A882";
const GREEN_900 = "#144D38";
const AMBER_50 = "#FEF6E7";
const AMBER_500 = "#F0A91B";
const GRAY_300 = "#C2C9D1";
const GRAY_400 = "#98A2AE";
const GRAY_600 = "#566270";
const GRAY_900 = "#16202B";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";
const SHADOW_SM = "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)";

export const DOW_ABBR = ["LUN.", "MAR.", "MER.", "JEU.", "VEN.", "SAM.", "DIM."];
const MONTH_HEADER_LABELS = ["L", "M", "M", "J", "V", "S", "D"];

// ── Date helpers (purs, aucune dépendance métier) ───────────────────────────
function pad2(value: number): string {
  return String(value).padStart(2, "0");
}

export function formatDateToYmd(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

export function isValidYmd(value: string | null): value is string {
  if (!value) return false;
  return /^\d{4}-\d{2}-\d{2}$/.test(value);
}

export function parseYmdToLocalDate(value: string): Date {
  const [year, month, day] = value.split("-").map(Number);
  return new Date(year, month - 1, day, 0, 0, 0, 0);
}

export function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0, 0);
}

export function addDays(date: Date, days: number): Date {
  const copy = new Date(date);
  copy.setDate(copy.getDate() + days);
  return copy;
}

export function addMinutes(date: Date, minutes: number): Date {
  return new Date(date.getTime() + minutes * 60 * 1000);
}

export function startOfWeek(date: Date): Date {
  const day = date.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  return startOfDay(addDays(date, diff));
}

export function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1, 0, 0, 0, 0);
}

export function getRange(view: ViewMode, dateYmd: string): { from: string; to: string } {
  const baseDate = parseYmdToLocalDate(dateYmd);

  if (view === "month") {
    const from = startOfMonth(baseDate);
    const to = new Date(from.getFullYear(), from.getMonth() + 1, 1, 0, 0, 0, 0);
    return { from: from.toISOString(), to: to.toISOString() };
  }

  const from = startOfWeek(baseDate);
  const to = addDays(from, 7);
  return { from: from.toISOString(), to: to.toISOString() };
}

export function getSafeView(value: string | null): ViewMode {
  if (value === "week" || value === "month") return value;
  return "month";
}

export function shiftDate(dateYmd: string, view: ViewMode, direction: -1 | 1): string {
  const date = parseYmdToLocalDate(dateYmd);
  if (view === "month") {
    return formatDateToYmd(new Date(date.getFullYear(), date.getMonth() + direction, 1));
  }
  return formatDateToYmd(addDays(date, direction * 7));
}

export function formatDisplayDate(dateYmd: string, view: ViewMode): string {
  const date = parseYmdToLocalDate(dateYmd);

  if (view === "month") {
    return date.toLocaleDateString("fr-BE", { month: "long", year: "numeric" });
  }

  const weekStart = startOfWeek(date);
  const weekEnd = addDays(weekStart, 6);
  const startLabel = weekStart.toLocaleDateString("fr-BE", {
    day: "2-digit",
    month: "short",
    year: weekStart.getFullYear() !== weekEnd.getFullYear() ? "numeric" : undefined,
  });
  const endLabel = weekEnd.toLocaleDateString("fr-BE", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
  return `${startLabel} — ${endLabel}`;
}

export function normalizeMissionInterval(mission: { startAt: string; endAt?: string | null }) {
  const startDate = new Date(mission.startAt);
  const startMs = startDate.getTime();
  if (!Number.isFinite(startMs)) return null;

  const rawEndDate = mission.endAt ? new Date(mission.endAt) : null;
  const rawEndMs = rawEndDate ? rawEndDate.getTime() : Number.NaN;
  const endDate =
    rawEndDate && Number.isFinite(rawEndMs) && rawEndMs > startMs
      ? rawEndDate
      : addMinutes(startDate, 1);

  return { start: startDate.toISOString(), end: endDate.toISOString(), startMs, endMs: endDate.getTime() };
}

export function formatMissionTime(startAt: string): string {
  const date = new Date(startAt);
  if (!Number.isFinite(date.getTime())) return "";
  return date.toLocaleTimeString("fr-BE", { hour: "2-digit", minute: "2-digit" });
}

export function getMissionStartDayKey(mission: { startAt: string }): string | null {
  const date = new Date(mission.startAt);
  if (!Number.isFinite(date.getTime())) return null;
  return formatDateToYmd(date);
}

export function compareMissionsByStart(a: { id: number; startAt: string }, b: { id: number; startAt: string }): number {
  const aTime = new Date(a.startAt).getTime();
  const bTime = new Date(b.startAt).getTime();
  const safeA = Number.isFinite(aTime) ? aTime : Number.MAX_SAFE_INTEGER;
  const safeB = Number.isFinite(bTime) ? bTime : Number.MAX_SAFE_INTEGER;
  return safeA !== safeB ? safeA - safeB : a.id - b.id;
}

/** Grille complète (semaines pleines, lundi en premier) pour le mois de dateYmd. */
export function buildMonthGridCells(dateYmd: string): Array<{ dateYmd: string; dayNumber: number; inCurrentMonth: boolean }> {
  const monthStart = startOfMonth(parseYmdToLocalDate(dateYmd));
  const gridStart = startOfWeek(monthStart);
  const monthIndex = monthStart.getMonth();

  const cells: Array<{ dateYmd: string; dayNumber: number; inCurrentMonth: boolean }> = [];
  for (let i = 0; i < 42; i++) {
    const d = addDays(gridStart, i);
    cells.push({ dateYmd: formatDateToYmd(d), dayNumber: d.getDate(), inCurrentMonth: d.getMonth() === monthIndex });
  }

  let lastInMonth = 0;
  for (let i = 0; i < cells.length; i++) if (cells[i].inCurrentMonth) lastInMonth = i;
  const neededRows = Math.ceil((lastInMonth + 1) / 7);
  return cells.slice(0, neededRows * 7);
}

// ── Segmented control (Semaine/Mois) ────────────────────────────────────────
export function SegmentedControl({ view, onChange }: { view: ViewMode; onChange: (v: ViewMode) => void }) {
  const seg = (key: ViewMode, label: string) => (
    <Box
      component="button"
      type="button"
      onClick={() => onChange(key)}
      sx={{
        height: 36, px: "16px", borderRadius: "10px", border: "none", fontFamily: "inherit",
        fontSize: 13.5, fontWeight: view === key ? 700 : 600, cursor: "pointer",
        background: view === key ? "#F1F4F7" : "transparent",
        color: view === key ? GRAY_900 : GRAY_600,
        boxShadow: view === key ? SHADOW_XS : "none",
      }}
    >
      {label}
    </Box>
  );

  return (
    <Box sx={{ display: "flex", gap: "4px", background: "#fff", borderRadius: "13px", padding: "4px", boxShadow: SHADOW_SM, width: "max-content" }}>
      {seg("week", "Semaine")}
      {seg("month", "Mois")}
    </Box>
  );
}

// ── Vue semaine : 7 chips jour ───────────────────────────────────────────────
export function WeekStrip({
  date, todayYmd, hasMissionOn, onDayClick,
}: {
  date: string;
  todayYmd: string;
  hasMissionOn: (dayKey: string) => boolean;
  onDayClick: (dayKey: string) => void;
}) {
  const start = startOfWeek(parseYmdToLocalDate(date));
  const days = Array.from({ length: 7 }, (_, i) => addDays(start, i));

  return (
    <Box sx={{ display: "flex", gap: "7px" }}>
      {days.map((d, i) => {
        const dayKey = formatDateToYmd(d);
        const isToday = dayKey === todayYmd;
        const hasMission = hasMissionOn(dayKey);
        return (
          <Box
            key={dayKey}
            component="button"
            type="button"
            onClick={() => onDayClick(dayKey)}
            sx={{
              flex: 1, height: 66, borderRadius: "14px", border: "none", cursor: "pointer", fontFamily: "inherit",
              display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: "3px",
              background: isToday ? GREEN_900 : "#fff",
              color: isToday ? "#fff" : "inherit",
              boxShadow: isToday ? "0 5px 14px rgba(20,77,56,.35)" : SHADOW_XS,
              outline: isToday ? "2px solid #fff" : "none",
              outlineOffset: isToday ? "-4px" : 0,
            }}
          >
            <Box sx={{ fontSize: 11, fontWeight: 600, opacity: isToday ? 0.85 : 1, color: isToday ? "#fff" : GRAY_400 }}>
              {DOW_ABBR[i]}
            </Box>
            <Box sx={{ fontSize: 17, fontWeight: 800, fontVariantNumeric: "tabular-nums" }}>{d.getDate()}</Box>
            <Box sx={{ width: 5, height: 5, borderRadius: "999px", background: hasMission ? (isToday ? "#fff" : GREEN_500) : "transparent" }} />
          </Box>
        );
      })}
    </Box>
  );
}

export type MonthDayMeta = { hasConflict: boolean; hasSecondary: boolean; hasMission: boolean };

// ── Vue mois : grille + légende ──────────────────────────────────────────────
// `hasSecondary`/`secondaryLabel`/`secondaryColor` remplacent l'ancien "hasToEncode"
// hardcodé — l'instrumentiste passe `list.some(isPendingEncoding)` (défauts inchangés,
// "À encoder" ambre) ; le chirurgien passe `list.some(m => !m.covered)` avec
// secondaryLabel="À couvrir".
export function MonthGrid({
  date, todayYmd, dayMeta, onDayClick, secondaryLabel = "À encoder",
  secondaryBg = AMBER_50, secondaryDot = AMBER_500,
}: {
  date: string;
  todayYmd: string;
  dayMeta: Map<string, MonthDayMeta>;
  onDayClick: (dayKey: string) => void;
  secondaryLabel?: string;
  secondaryBg?: string;
  secondaryDot?: string;
}) {
  const cells = React.useMemo(() => buildMonthGridCells(date), [date]);

  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", padding: "12px", boxShadow: SHADOW_XS }}>
      <Box sx={{ display: "grid", gridTemplateColumns: "repeat(7, minmax(0,1fr))", gap: "2px" }}>
        {MONTH_HEADER_LABELS.map((label, i) => (
          <Box key={i} sx={{ height: 28, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 11, fontWeight: 700, color: GRAY_400 }}>
            {label}
          </Box>
        ))}
        {cells.map((cell) => {
          const meta = dayMeta.get(cell.dateYmd);
          const isToday = cell.dateYmd === todayYmd;
          const bg = isToday ? GREEN_900 : meta?.hasSecondary ? secondaryBg : meta?.hasMission ? GREEN_50 : "transparent";
          const dotColor = isToday ? GREEN_300 : meta?.hasSecondary ? secondaryDot : meta?.hasMission ? GREEN_500 : "transparent";
          const textColor = isToday ? "#fff" : !cell.inCurrentMonth ? GRAY_300 : "inherit";
          return (
            <Box
              key={cell.dateYmd}
              component="button"
              type="button"
              onClick={() => onDayClick(cell.dateYmd)}
              disabled={!meta?.hasMission}
              sx={{
                height: 44, borderRadius: "10px", border: "none", cursor: meta?.hasMission ? "pointer" : "default",
                background: bg, color: textColor, position: "relative",
                display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: "3px",
                fontFamily: "inherit",
              }}
            >
              <Box sx={{ fontSize: 13.5, fontWeight: 600, fontVariantNumeric: "tabular-nums", lineHeight: 1 }}>{cell.dayNumber}</Box>
              <Box sx={{ width: 5, height: 5, borderRadius: "999px", background: dotColor }} />
              {meta?.hasConflict && (
                <Box sx={{ position: "absolute", top: 2, right: 4, fontSize: 9, lineHeight: 1 }} title="Conflit potentiel">⚠</Box>
              )}
            </Box>
          );
        })}
      </Box>
      <Box sx={{ display: "flex", gap: "16px", mt: "12px", pt: "12px", borderTop: "1px dashed", borderColor: "grey.200" }}>
        <Stack direction="row" alignItems="center" spacing={0.75}>
          <Box sx={{ width: 6, height: 6, borderRadius: "999px", background: GREEN_500 }} />
          <Typography sx={{ fontSize: 12, color: GRAY_600 }}>Mission</Typography>
        </Stack>
        <Stack direction="row" alignItems="center" spacing={0.75}>
          <Box sx={{ width: 6, height: 6, borderRadius: "999px", background: secondaryDot }} />
          <Typography sx={{ fontSize: 12, color: GRAY_600 }}>{secondaryLabel}</Typography>
        </Stack>
      </Box>
    </Box>
  );
}

// ── Ligne mission générique ──────────────────────────────────────────────────
// Pur composant de présentation — `subtitlePerson` (chirurgien pour l'instrumentiste,
// instrumentiste pour le chirurgien) et `statusInfo` sont calculés par l'appelant.
export function MissionListRow({
  mission, subtitlePerson, statusInfo, dateTileVariant, onClick,
}: {
  mission: { id: number; startAt?: string; endAt?: string; site?: { name?: string | null } | null };
  subtitlePerson: string;
  statusInfo: { variant: StatusPillVariant; label: string; withDot?: boolean };
  dateTileVariant: DateTileVariant;
  onClick: () => void;
}) {
  const start = mission.startAt ? new Date(mission.startAt) : null;
  const timeLine = mission.startAt && mission.endAt
    ? `${formatMissionTime(mission.startAt)} → ${formatMissionTime(mission.endAt)}`
    : "—";

  return (
    <Box
      component="button"
      type="button"
      onClick={onClick}
      sx={{
        display: "flex", alignItems: "center", gap: "14px", width: "100%", textAlign: "left",
        background: "#fff", border: "1px solid #E7EBEF", borderRadius: "16px", padding: "14px 16px",
        boxShadow: SHADOW_XS, cursor: "pointer", fontFamily: "inherit",
      }}
    >
      <DateTile
        day={start ? String(start.getDate()).padStart(2, "0") : "—"}
        month={start ? start.toLocaleDateString("fr-BE", { month: "short" }).replace(".", "").toUpperCase() : ""}
        variant={dateTileVariant}
        preset="list"
      />
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700 }} noWrap>{mission.site?.name ?? "—"}</Typography>
        <Typography sx={{ mt: "3px", fontSize: 13, color: "text.secondary", fontVariantNumeric: "tabular-nums" }} noWrap>
          {timeLine}{subtitlePerson ? ` · ${subtitlePerson}` : ""}
        </Typography>
      </Box>
      <StatusPill variant={statusInfo.variant} label={statusInfo.label} withDot={statusInfo.withDot} />
    </Box>
  );
}

// ── État vide simple ─────────────────────────────────────────────────────────
export function EmptyStateRow({ text }: { text: string }) {
  return (
    <Box sx={{ background: "#fff", border: "1px solid #E7EBEF", borderRadius: "14px", padding: "14px 16px", textAlign: "center" }}>
      <Typography sx={{ fontSize: 13.5, color: GRAY_600 }}>{text}</Typography>
    </Box>
  );
}
