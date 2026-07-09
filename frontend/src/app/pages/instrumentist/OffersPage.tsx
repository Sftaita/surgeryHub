import * as React from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Box, CircularProgress, Stack, Typography } from "@mui/material";
import { Navigate, useNavigate } from "react-router-dom";

import dayjs from "dayjs";
import "dayjs/locale/fr";

import {
  fetchInstrumentistOffersWithFallback,
  claimMission,
} from "../../features/missions/api/missions.api";
import type { Mission, MissionType } from "../../features/missions/api/missions.types";
import { useToast } from "../../ui/toast/useToast";
import { useAuth } from "../../auth/AuthContext";
import { isMobileRole } from "../../auth/roles";
import { useNotifications } from "../../features/push/useNotifications";
import { requestMissionSync } from "../../features/missions/sync/missionSyncBus";
import { DateTile } from "../../ui/mobile/DateTile";
import { StatusPill } from "../../ui/mobile/StatusPill";

dayjs.locale("fr");

const GREEN_50 = "#EFFAF5";
const GREEN_100 = "#DDF4EA";
const GREEN_500 = "#42A882";
const GREEN_600 = "#338F6E";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GREEN_900 = "#144D38";
const GRAY_200 = "#DDE2E8";
const GRAY_400 = "#98A2AE";
const GRAY_600 = "#566270";
const GRAY_800 = "#243240";
const BORDER_SUBTLE = "#E7EBEF";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

type FilterKey = "all" | MissionType;

const CHIPS: Array<{ key: FilterKey; label: string }> = [
  { key: "all", label: "Toutes" },
  { key: "BLOCK", label: "Bloc opératoire" },
  { key: "CONSULTATION", label: "Consultation" },
];

function missionTypeLabel(type: MissionType): string {
  return type === "CONSULTATION" ? "Consultation" : "Bloc opératoire";
}

function formatTime(iso?: string): string {
  if (!iso) return "—";
  return dayjs(iso).format("HH[h]mm");
}

function durationLabel(startAt?: string, endAt?: string): string {
  if (!startAt || !endAt) return "";
  const mins = dayjs(endAt).diff(dayjs(startAt), "minute");
  if (mins <= 0) return "";
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return m > 0 ? `${h}h${String(m).padStart(2, "0")}` : `${h}h00`;
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

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

function ClockIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke={GREEN_700} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
    </svg>
  );
}
function UserIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke={GREEN_700} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="8" r="5" /><path d="M20 21a8 8 0 0 0-16 0" />
    </svg>
  );
}
function CheckIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={GREEN_700} strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}

// ─── Offer card ─────────────────────────────────────────────────────────────
function OfferCard({
  mission,
  claimed,
  onClaim,
  onViewPlanning,
  loading,
  disabled,
}: {
  mission: Mission;
  claimed: boolean;
  onClaim: () => void;
  onViewPlanning: () => void;
  loading: boolean;
  disabled: boolean;
}) {
  const start = mission.startAt ? dayjs(mission.startAt) : null;
  const surgeon = surgeonLabel(mission);
  const dur = durationLabel(mission.startAt, mission.endAt);

  return (
    <Box sx={{ background: "#fff", border: `1px solid ${BORDER_SUBTLE}`, borderRadius: "18px", padding: "16px", boxShadow: SHADOW_XS, opacity: 1, transition: "opacity 300ms" }}>
      <Stack direction="row" spacing={1.75} alignItems="center">
        <DateTile
          day={start ? start.format("DD") : "—"}
          month={start ? start.format("MMM").replace(".", "").toUpperCase() : ""}
          variant={claimed ? "aVenir" : "proposee"}
          preset="offer"
        />
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Typography sx={{ fontSize: 16, fontWeight: 800, letterSpacing: "-0.01em", color: GREEN_700 }} noWrap>
            {mission.site?.name ?? "—"}
          </Typography>
          {mission.site?.address && (
            <Typography sx={{ mt: "2px", fontSize: 13, color: GRAY_600 }} noWrap>
              {mission.site.address}
            </Typography>
          )}
        </Box>
        <StatusPill variant={claimed ? "aVenir" : "proposee"} label={claimed ? "Attribuée" : "Proposée"} />
      </Stack>

      <Box sx={{ borderTop: "1px dashed", borderColor: GRAY_200, my: "14px" }} />

      <Stack spacing={1.25}>
        <Stack direction="row" alignItems="center" spacing={1.375}>
          <Box sx={{ width: 34, height: 34, borderRadius: "999px", background: GREEN_50, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
            <ClockIcon />
          </Box>
          <Typography sx={{ fontSize: 14, fontWeight: 700, color: GRAY_800, fontVariantNumeric: "tabular-nums" }}>
            {formatTime(mission.startAt)} → {formatTime(mission.endAt)}
          </Typography>
          {dur && <Typography sx={{ fontSize: 13, color: GRAY_400, fontVariantNumeric: "tabular-nums" }}>· {dur}</Typography>}
        </Stack>
        {surgeon && (
          <Stack direction="row" alignItems="center" spacing={1.375}>
            <Box sx={{ width: 34, height: 34, borderRadius: "999px", background: GREEN_50, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
              <UserIcon />
            </Box>
            <Typography sx={{ fontSize: 14, color: "#3A4754" }}>{surgeon}</Typography>
          </Stack>
        )}
        <Stack direction="row" alignItems="center" spacing={1} sx={{ mt: "2px" }}>
          <Box sx={{ display: "inline-flex", alignItems: "center", height: 24, px: "10px", borderRadius: "999px", background: GREEN_100, color: GREEN_800, fontSize: 12, fontWeight: 700 }}>
            {missionTypeLabel(mission.type)}
          </Box>
        </Stack>
      </Stack>

      {claimed ? (
        <Stack direction="row" alignItems="center" spacing={1.125} sx={{ mt: "14px", padding: "11px 13px", background: GREEN_50, borderRadius: "11px" }}>
          <CheckIcon />
          <Typography sx={{ flex: 1, fontSize: 13.5, fontWeight: 600, color: GREEN_800 }}>Ajoutée à votre planning</Typography>
          <Box
            component="button"
            type="button"
            onClick={onViewPlanning}
            sx={{ border: "none", background: "none", p: 0, fontWeight: 800, color: GREEN_700, fontFamily: "inherit", fontSize: 13.5, cursor: "pointer" }}
          >
            Voir
          </Box>
        </Stack>
      ) : (
        <Box
          component="button"
          type="button"
          disabled={loading || disabled}
          onClick={onClaim}
          sx={{
            mt: "16px", width: "100%", height: 46, border: "none", borderRadius: "12px",
            background: GREEN_500, color: "#fff", fontFamily: "inherit", fontSize: 14, fontWeight: 700, cursor: "pointer",
            boxShadow: "0 4px 12px rgba(66,168,130,.32)", transition: "background 150ms",
            "&:hover": { background: GREEN_600 }, "&:disabled": { opacity: 0.6, cursor: "default" },
          }}
        >
          {loading ? "…" : "Prendre la mission"}
        </Box>
      )}
    </Box>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────
export default function OffersPage() {
  const toast = useToast();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const { state } = useAuth();
  const { addNotification } = useNotifications();

  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  const role = state.user.role;
  if (!isMobileRole(role) || role !== "INSTRUMENTIST")
    return <Navigate to="/app/m/missions" replace />;

  const [loadingClaimId, setLoadingClaimId] = React.useState<number | null>(null);
  const [filter, setFilter] = React.useState<FilterKey>("all");
  // La mission "prise" quitte immédiatement les résultats de la requête offres
  // (elle n'est plus une offre) — conservée localement pour afficher la
  // confirmation "Ajoutée à votre planning" au lieu de la faire disparaître.
  const [claimedMissions, setClaimedMissions] = React.useState<Mission[]>([]);

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ["missions", "offers"],
    queryFn: () => fetchInstrumentistOffersWithFallback(1, 100),
  });

  const openMissions = data?.items ?? [];
  const allMissions = [...claimedMissions, ...openMissions.filter((m) => !claimedMissions.some((c) => c.id === m.id))];
  const missions = filter === "all" ? allMissions : allMissions.filter((m) => m.type === filter);

  const handleClaim = async (mission: Mission) => {
    if (loadingClaimId !== null) return;
    setLoadingClaimId(mission.id);
    try {
      await claimMission(mission.id);
      setClaimedMissions((prev) => [...prev, mission]);
      addNotification({
        type: "MISSION_ASSIGNED",
        title: "Mission attribuée",
        body: `Mission #${mission.id} — ${mission.site?.name ?? ""}`,
        data: { missionId: mission.id },
        readAt: null,
      });
      toast.success(
        "Mission attribuée.\nMerci de consulter le programme opératoire afin de préparer au mieux cette journée.",
      );
      queryClient.invalidateQueries({ queryKey: ["missions"] });
      requestMissionSync();
    } catch (err: any) {
      const status = err?.response?.status;
      if (status === 409) {
        toast.warning(extractErrorMessage(err));
      } else if (status === 403) {
        toast.error("Accès refusé");
        navigate("/app/m/missions", { replace: true });
      } else {
        toast.error(extractErrorMessage(err));
      }
      queryClient.invalidateQueries({ queryKey: ["missions"] });
      requestMissionSync();
    } finally {
      setLoadingClaimId(null);
    }
  };

  if (isLoading) {
    return (
      <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
        <CircularProgress size={28} />
      </Box>
    );
  }

  return (
    <Stack spacing={2}>
      {isFetching && (
        <Box sx={{ display: "flex", justifyContent: "flex-end" }}>
          <CircularProgress size={16} />
        </Box>
      )}

      <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
        {CHIPS.map((c) => {
          const active = filter === c.key;
          return (
            <Box
              key={c.key}
              component="button"
              type="button"
              onClick={() => setFilter(c.key)}
              sx={{
                height: 38, px: "15px", borderRadius: "999px", border: "none", fontFamily: "inherit",
                fontSize: 13.5, fontWeight: 600, cursor: "pointer",
                background: active ? GREEN_900 : "#fff",
                color: active ? "#fff" : GRAY_600,
                boxShadow: active ? `0 0 0 2px #fff, ${SHADOW_XS}` : SHADOW_XS,
              }}
            >
              {c.label}
            </Box>
          );
        })}
      </Stack>

      {missions.length === 0 ? (
        <Box sx={{ background: "#fff", border: `1px solid ${BORDER_SUBTLE}`, borderRadius: "18px", p: 4, textAlign: "center" }}>
          <Typography variant="body1" color="text.secondary" fontWeight={600}>
            Aucune offre disponible
          </Typography>
          <Typography variant="caption" color="text.disabled">
            Revenez plus tard pour consulter les nouvelles missions
          </Typography>
        </Box>
      ) : (
        <Stack spacing={1.625}>
          {missions.map((m) => (
            <OfferCard
              key={m.id}
              mission={m}
              claimed={claimedMissions.some((c) => c.id === m.id)}
              onClaim={() => handleClaim(m)}
              onViewPlanning={() => navigate("/app/i/planning")}
              loading={loadingClaimId === m.id}
              disabled={loadingClaimId !== null && loadingClaimId !== m.id}
            />
          ))}
        </Stack>
      )}
    </Stack>
  );
}
