import * as React from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { Box } from "@mui/material";

import dayjs from "dayjs";
import calendar from "dayjs/plugin/calendar";
import "dayjs/locale/fr";

import {
  fetchMissions,
  fetchInstrumentistOffersWithFallback,
} from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";

dayjs.extend(calendar);
dayjs.locale("fr");

// ── Design tokens ────────────────────────────────────────────────────────────
const GREEN_50  = "#EFFAF5";
const GREEN_100 = "#DDF4EA";
const GREEN_300 = "#8FDABF";
const GREEN_400 = "#63C9A3";
const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GREEN_900 = "#144D38";
const AMBER_50  = "#FEF6E7";
const AMBER_600 = "#D9920B";
const AMBER_700 = "#B7791F";
const GRAY_150  = "#E7EBEF";
const GRAY_200  = "#DDE2E8";
const GRAY_300  = "#C2C9D1";
const GRAY_400  = "#98A2AE";
const GRAY_500  = "#727E8C";
const GRAY_800  = "#243240";
const BORDER_SUBTLE = "#E7EBEF";
const AMBER_100 = "#FBEACB";
const SHADOW_MD = "0 2px 6px rgba(22,32,43,.06), 0 8px 20px rgba(22,32,43,.08)";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";
const SHADOW_SM = "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)";

const TODAY_STATUSES = "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED";
const ENCODING_PENDING_STATUSES = "ASSIGNED,IN_PROGRESS,DECLARED";

function todayRange() {
  const now = new Date();
  const from = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
  const to = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59);
  return { from: from.toISOString(), to: to.toISOString() };
}

function upcomingRange() {
  const now = new Date();
  const from = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 0, 0, 0);
  const to = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 30, 23, 59, 59);
  return { from: from.toISOString(), to: to.toISOString() };
}

function formatTime(iso?: string): string {
  if (!iso) return "—";
  return dayjs(iso).format("HH[h]mm");
}

function surgeonLabel(mission: Mission): string | null {
  const s = mission.surgeon;
  if (!s) return null;
  const fn = (s.firstname ?? "").trim();
  const ln = (s.lastname ?? "").trim();
  const full = `${fn} ${ln}`.trim();
  if (full) return `Dr. ${full}`;
  return (s as any).displayName?.trim() || null;
}

// ── Icons (Lucide-style outline, mirrors handoff) ────────────────────────────
function ClockIcon({ color = GRAY_800, size = 19 }: { color?: string; size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
    </svg>
  );
}
function UserIcon({ color = GRAY_800, size = 19 }: { color?: string; size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="8" r="5" /><path d="M20 21a8 8 0 0 0-16 0" />
    </svg>
  );
}
function ChevronRightIcon({ color = GRAY_300, size = 16 }: { color?: string; size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
      <path d="m9 18 6-6-6-6" />
    </svg>
  );
}
function PlusCircleIcon() {
  return (
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="M12 8v8M8 12h8" />
    </svg>
  );
}
function AlertClockIcon() {
  return (
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke={AMBER_700} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 2v4M12 18v4M5 7l2.8 2.8M16.2 14.2 19 17M2 12h4M18 12h4M5 17l2.8-2.8M16.2 9.8 19 7" />
    </svg>
  );
}

// ── Hero card (mission du jour) ──────────────────────────────────────────────
function TodayMissionHero({ mission }: { mission: Mission }) {
  const navigate = useNavigate();
  const canEncoding =
    mission.allowedActions?.includes("encoding") ||
    mission.allowedActions?.includes("edit_encoding");

  const timeLine = mission.startAt && mission.endAt
    ? `${formatTime(mission.startAt)} → ${formatTime(mission.endAt)}`
    : "—";
  const durationLabel = mission.startAt && mission.endAt
    ? (() => {
        const mins = dayjs(mission.endAt).diff(dayjs(mission.startAt), "minute");
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        return m > 0 ? `· ${h}h${String(m).padStart(2, "0")}` : `· ${h}h00`;
      })()
    : "";
  const surgeon = surgeonLabel(mission);
  const inProgress = mission.status === "IN_PROGRESS";
  const start = mission.startAt ? dayjs(mission.startAt) : null;
  const photo = mission.site?.photoPath ?? null;

  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", overflow: "hidden", boxShadow: SHADOW_MD }}>
      <Box
        sx={{
          position: "relative",
          height: 186,
          background: photo
            ? `url('${photo}') center/cover no-repeat`
            : `linear-gradient(140deg, ${GREEN_800}, ${GREEN_500})`,
        }}
      >
        <Box sx={{ position: "absolute", inset: 0, background: "linear-gradient(180deg, rgba(11,19,32,.15) 0%, rgba(20,77,56,.82) 100%)" }} />
        <Box sx={{ position: "absolute", inset: 0, p: "14px 16px", display: "flex", flexDirection: "column" }}>
          <Box sx={{ display: "flex", alignItems: "center", gap: "10px" }}>
            <Box sx={{ display: "inline-flex", alignItems: "center", height: 25, px: "11px", borderRadius: "999px", background: "rgba(20,77,56,.9)", color: "#fff", fontSize: 10.5, fontWeight: 800, letterSpacing: "0.06em", whiteSpace: "nowrap" }}>
              MISSION DU JOUR
            </Box>
            <Box sx={{ flex: 1 }} />
            {inProgress && (
              <Box sx={{ display: "inline-flex", alignItems: "center", gap: "7px", height: 26, px: "11px", borderRadius: "999px", background: "rgba(20,77,56,.9)", color: "#fff", fontSize: 12, fontWeight: 700, flexShrink: 0 }}>
                <Box
                  sx={{
                    width: 7, height: 7, borderRadius: "999px", background: GREEN_300,
                    animation: "shPulse 1.6s ease-in-out infinite",
                    "@keyframes shPulse": { "0%,100%": { opacity: 1 }, "50%": { opacity: 0.35 } },
                  }}
                />
                En cours
              </Box>
            )}
          </Box>
          <Box sx={{ flex: 1 }} />
          <Box sx={{ display: "flex", alignItems: "flex-end", gap: "12px" }}>
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Box sx={{ fontSize: 18, fontWeight: 800, letterSpacing: "-0.01em", color: "#fff", textShadow: "0 1px 8px rgba(11,19,32,.4)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                {mission.site?.name ?? "—"}
              </Box>
              {mission.site?.address && (
                <Box sx={{ mt: "2px", fontSize: 13, color: "rgba(255,255,255,.85)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                  {mission.site.address}
                </Box>
              )}
            </Box>
            {start && (
              <Box sx={{ width: 48, height: 52, flexShrink: 0, borderRadius: "13px", display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: "1px", background: "rgba(255,255,255,.95)", color: GREEN_900, boxShadow: SHADOW_SM }}>
                <Box sx={{ fontSize: 17, fontWeight: 800, lineHeight: 1, fontVariantNumeric: "tabular-nums" }}>{start.format("DD")}</Box>
                <Box sx={{ fontSize: 9.5, fontWeight: 700, letterSpacing: "0.08em" }}>{start.format("MMM").replace(".", "").toUpperCase()}</Box>
              </Box>
            )}
          </Box>
        </Box>
      </Box>

      <Box sx={{ p: "16px 18px 18px" }}>
        <Box sx={{ display: "flex", alignItems: "center", gap: "11px" }}>
          <ClockIcon />
          <Box sx={{ fontSize: 21, fontWeight: 800, letterSpacing: "-0.02em", fontVariantNumeric: "tabular-nums" }}>{timeLine}</Box>
          {durationLabel && <Box sx={{ fontSize: 14, color: GRAY_400, fontVariantNumeric: "tabular-nums" }}>{durationLabel}</Box>}
        </Box>
        {surgeon && (
          <Box sx={{ display: "flex", alignItems: "center", gap: "11px", mt: "11px" }}>
            <UserIcon />
            <Box sx={{ fontSize: 14.5, fontWeight: 600, color: GRAY_800 }}>{surgeon}</Box>
          </Box>
        )}
        <Box
          component="button"
          type="button"
          onClick={() =>
            navigate(canEncoding ? `/app/i/missions/${mission.id}/encoding` : `/app/i/missions/${mission.id}`)
          }
          sx={{
            mt: "16px",
            width: "100%",
            height: 52,
            border: "none",
            borderRadius: "13px",
            background: GREEN_800,
            color: "#fff",
            fontFamily: "inherit",
            fontSize: 15,
            fontWeight: 700,
            cursor: "pointer",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            gap: "9px",
            boxShadow: "0 5px 14px rgba(20,77,56,.3)",
            transition: "background 150ms",
            "&:hover": { background: GREEN_900 },
            "&:active": { transform: "translateY(0.5px)" },
          }}
        >
          {canEncoding ? "Encoder la mission" : "Voir le détail de la mission"}
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
            <path d="m9 18 6-6-6-6" />
          </svg>
        </Box>
      </Box>
    </Box>
  );
}

// ── No mission state ─────────────────────────────────────────────────────────
function NoMissionCard() {
  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, p: "28px", textAlign: "center" }}>
      <Box sx={{ fontSize: 15, fontWeight: 600, color: GRAY_500 }}>Aucune mission aujourd'hui</Box>
      <Box sx={{ mt: "4px", fontSize: 13, color: GRAY_400 }}>Consultez les offres disponibles ci-dessous</Box>
    </Box>
  );
}

// ── "À venir" card ────────────────────────────────────────────────────────────
function UpcomingCard({ mission }: { mission: Mission }) {
  const navigate = useNavigate();
  const start = mission.startAt ? dayjs(mission.startAt) : null;
  const timeLine = mission.startAt && mission.endAt
    ? `${formatTime(mission.startAt)} → ${formatTime(mission.endAt)}`
    : "—";

  return (
    <Box
      onClick={() => navigate(`/app/i/missions/${mission.id}`)}
      sx={{
        background: "#fff",
        borderRadius: "16px",
        padding: "13px 15px",
        boxShadow: SHADOW_XS,
        display: "flex",
        alignItems: "stretch",
        gap: "14px",
        cursor: "pointer",
        transition: "box-shadow 150ms",
        "&:hover": { boxShadow: SHADOW_SM },
      }}
    >
      <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", lineHeight: 1.15, flexShrink: 0, minWidth: 40 }}>
        <Box sx={{ fontSize: 10.5, fontWeight: 700, color: GRAY_400, letterSpacing: "0.05em" }}>
          {start ? start.format("ddd").toUpperCase() : ""}
        </Box>
        <Box sx={{ fontSize: 21, fontWeight: 800, fontVariantNumeric: "tabular-nums" }}>{start ? start.format("DD") : "—"}</Box>
        <Box sx={{ fontSize: 10.5, fontWeight: 700, color: GRAY_400, letterSpacing: "0.05em" }}>
          {start ? `${start.format("MMM").replace(".", "")}.` : ""}
        </Box>
      </Box>
      <Box sx={{ width: "1px", background: GRAY_150, flexShrink: 0 }} />
      <Box sx={{ flex: 1, minWidth: 0, display: "flex", flexDirection: "column", justifyContent: "center" }}>
        <Box sx={{ fontSize: 15, fontWeight: 700, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
          {mission.site?.name ?? "—"}
        </Box>
        {mission.site?.address && (
          <Box sx={{ mt: "2px", fontSize: 12.5, color: GRAY_500, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
            {mission.site.address}
          </Box>
        )}
        <Box sx={{ mt: "7px", display: "flex", alignItems: "center", gap: "10px" }}>
          <Box sx={{ fontSize: 14, fontWeight: 700, fontVariantNumeric: "tabular-nums" }}>{timeLine}</Box>
          <Box sx={{ flex: 1 }} />
          <Box sx={{ display: "inline-flex", alignItems: "center", height: 22, px: "9px", borderRadius: "999px", background: GREEN_100, color: GREEN_800, fontSize: 11.5, fontWeight: 700 }}>
            Confirmée
          </Box>
        </Box>
      </Box>
    </Box>
  );
}

// ── "À encoder" ambre card ───────────────────────────────────────────────────
function ToEncodeCard({ mission }: { mission: Mission }) {
  const navigate = useNavigate();
  const start = mission.startAt ? dayjs(mission.startAt) : null;
  const dayLabel = start ? start.calendar(null, {
    sameDay: "[Aujourd'hui]",
    lastDay: "[Hier]",
    lastWeek: "dddd",
    sameElse: "DD/MM",
  }) : "";
  const timeLine = mission.startAt && mission.endAt
    ? `${formatTime(mission.startAt)} → ${formatTime(mission.endAt)}`
    : "—";

  return (
    <Box sx={{ display: "flex", alignItems: "center", gap: "14px", background: "#fff", border: `1px solid ${AMBER_100}`, borderRadius: "16px", padding: "13px 15px", boxShadow: SHADOW_XS }}>
      <Box sx={{ width: 42, height: 42, borderRadius: "999px", background: AMBER_50, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
        <AlertClockIcon />
      </Box>
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Box sx={{ display: "flex", alignItems: "center", gap: "8px" }}>
          <Box sx={{ fontSize: 15, fontWeight: 700, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
            {mission.site?.name ?? "—"}
          </Box>
          <Box sx={{ display: "inline-flex", alignItems: "center", height: 22, px: "9px", borderRadius: "999px", background: AMBER_50, color: AMBER_700, fontSize: 11.5, fontWeight: 700, flexShrink: 0 }}>
            À encoder
          </Box>
        </Box>
        <Box sx={{ mt: "3px", fontSize: 13, color: GRAY_500, fontVariantNumeric: "tabular-nums" }}>
          {dayLabel} · {timeLine}
        </Box>
      </Box>
      <Box
        component="button"
        type="button"
        onClick={() => navigate(`/app/i/missions/${mission.id}/encoding`)}
        sx={{
          height: 40, px: "16px", border: "none", borderRadius: "11px", background: AMBER_600, color: "#fff",
          fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, cursor: "pointer", flexShrink: 0,
          transition: "background 150ms", "&:hover": { background: AMBER_700 },
        }}
      >
        Encoder
      </Box>
    </Box>
  );
}

// ── Offer mini-card (rail) ───────────────────────────────────────────────────
function OfferMiniCard({ mission }: { mission: Mission }) {
  const navigate = useNavigate();
  const start = mission.startAt ? dayjs(mission.startAt) : null;

  return (
    <Box
      onClick={() => navigate("/app/i/offers")}
      sx={{
        minWidth: 256, flexShrink: 0, background: "#fff", border: `1px solid ${BORDER_SUBTLE}`, borderRadius: "16px",
        padding: "13px 14px", display: "flex", gap: "12px", alignItems: "center", cursor: "pointer", boxShadow: SHADOW_XS,
        transition: "border-color 150ms, box-shadow 150ms",
        "&:hover": { borderColor: GREEN_300, boxShadow: SHADOW_SM },
      }}
    >
      <Box sx={{ width: 46, height: 50, flexShrink: 0, borderRadius: "12px", display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: "1px", background: GREEN_100, color: GREEN_900 }}>
        <Box sx={{ fontSize: 17, fontWeight: 800, lineHeight: 1, fontVariantNumeric: "tabular-nums" }}>{start ? start.format("DD") : "—"}</Box>
        <Box sx={{ fontSize: 9.5, fontWeight: 700, letterSpacing: "0.08em" }}>{start ? start.format("MMM").replace(".", "").toUpperCase() : ""}</Box>
      </Box>
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Box sx={{ fontSize: 14, fontWeight: 700, color: GREEN_700, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
          {mission.site?.name ?? "—"}
        </Box>
        <Box sx={{ mt: "2px", fontSize: 12.5, color: GRAY_500, fontVariantNumeric: "tabular-nums" }}>
          {formatTime(mission.startAt)} → {formatTime(mission.endAt)}
        </Box>
      </Box>
      <ChevronRightIcon />
    </Box>
  );
}

// ── Main page ──────────────────────────────────────────────────────────────
export default function TodayPage() {
  const navigate = useNavigate();

  const { from, to } = React.useMemo(() => todayRange(), []);
  const { from: upFrom, to: upTo } = React.useMemo(() => upcomingRange(), []);

  const { data: todayData, isLoading: loadingToday } = useQuery({
    queryKey: ["missions", "today", { from, to }],
    queryFn: () => fetchMissions(1, 10, { assignedToMe: true, status: TODAY_STATUSES, from, to }),
    refetchInterval: 60_000,
  });

  const { data: upcomingData } = useQuery({
    queryKey: ["missions", "upcoming", { upFrom, upTo }],
    queryFn: () => fetchMissions(1, 6, { assignedToMe: true, status: "ASSIGNED,DECLARED", from: upFrom, to: upTo }),
    refetchInterval: 60_000,
  });

  const { data: pendingEncodingData } = useQuery({
    queryKey: ["missions", "pending-encoding"],
    queryFn: () => fetchMissions(1, 20, { assignedToMe: true, status: ENCODING_PENDING_STATUSES, to: from }),
    refetchInterval: 60_000,
  });

  const { data: offersData, isLoading: loadingOffers } = useQuery({
    queryKey: ["missions", "offers"],
    queryFn: () => fetchInstrumentistOffersWithFallback(1, 4),
    refetchInterval: 60_000,
  });

  const todayMissions = todayData?.items ?? [];
  const upcomingMissions = upcomingData?.items ?? [];
  const offers = offersData?.items ?? [];
  const mainMission = todayMissions[0] ?? null;

  const toEncodeMission = React.useMemo(() => {
    const candidates = (pendingEncodingData?.items ?? [])
      .filter((m) => m.id !== mainMission?.id && m.allowedActions?.some((a) => a === "encoding" || a === "edit_encoding"))
      .sort((a, b) => (a.startAt ?? "").localeCompare(b.startAt ?? ""));
    return candidates[0] ?? null;
  }, [pendingEncodingData, mainMission]);

  return (
    <Box sx={{ display: "flex", flexDirection: "column", gap: "20px" }}>
      {loadingToday ? null : mainMission ? <TodayMissionHero mission={mainMission} /> : <NoMissionCard />}

      {upcomingMissions.length > 0 && (
        <Box sx={{ display: "flex", flexDirection: "column", gap: "11px" }}>
          <Box sx={{ display: "flex", alignItems: "baseline", gap: "12px" }}>
            <Box sx={{ fontSize: 16, fontWeight: 800, letterSpacing: "-0.01em", whiteSpace: "nowrap", flexShrink: 0 }}>À venir</Box>
            <Box sx={{ flex: 1 }} />
            <Box
              component="button"
              type="button"
              onClick={() => navigate("/app/i/planning")}
              sx={{ border: "none", background: "none", p: 0, fontSize: 13.5, fontWeight: 700, color: GREEN_700, cursor: "pointer", textDecoration: "none", fontFamily: "inherit" }}
            >
              Voir tout le planning
            </Box>
          </Box>
          <Box
            sx={{
              display: "flex", gap: "12px", overflowX: "auto", mx: "-20px", px: "20px", pb: "6px",
              scrollbarWidth: "none", "&::-webkit-scrollbar": { display: "none" },
            }}
          >
            {upcomingMissions.map((m) => (
              <Box key={m.id} sx={{ minWidth: 272, flexShrink: 0 }}>
                <UpcomingCard mission={m} />
              </Box>
            ))}
          </Box>
        </Box>
      )}

      {toEncodeMission && <ToEncodeCard mission={toEncodeMission} />}

      <Box
        component="button"
        type="button"
        onClick={() => navigate("/app/i/missions/declare")}
        sx={{
          display: "flex", alignItems: "center", justifyContent: "center", gap: "9px", height: 52,
          border: `1.5px dashed ${GREEN_400}`, borderRadius: "14px", background: GREEN_50, color: GREEN_800,
          fontSize: 14.5, fontWeight: 700, cursor: "pointer", fontFamily: "inherit",
          transition: "background 150ms", "&:hover": { background: GREEN_100 },
        }}
      >
        <PlusCircleIcon />
        Déclarer une mission non prévue
      </Box>

      {!loadingOffers && offers.length > 0 && (
        <Box sx={{ display: "flex", flexDirection: "column", gap: "11px" }}>
          <Box sx={{ display: "flex", alignItems: "center", gap: "12px" }}>
            <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700, whiteSpace: "nowrap", flexShrink: 0 }}>
              OFFRES DISPONIBLES
            </Box>
            <Box sx={{ flex: 1, borderTop: `1px dashed ${GRAY_200}` }} />
            <Box
              component="button"
              type="button"
              onClick={() => navigate("/app/i/offers")}
              sx={{ border: "none", background: "none", p: 0, fontSize: 13.5, fontWeight: 700, color: GREEN_700, cursor: "pointer", textDecoration: "none", fontFamily: "inherit" }}
            >
              Tout voir
            </Box>
          </Box>
          <Box
            sx={{
              display: "flex", gap: "12px", overflowX: "auto", mx: "-20px", px: "20px", pb: "6px",
              scrollbarWidth: "none", "&::-webkit-scrollbar": { display: "none" },
            }}
          >
            {offers.map((m) => (
              <OfferMiniCard key={m.id} mission={m} />
            ))}
          </Box>
        </Box>
      )}
    </Box>
  );
}
