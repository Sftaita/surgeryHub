import * as React from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Box, Button, CircularProgress, Stack, Typography } from "@mui/material";
import { Navigate, useNavigate } from "react-router-dom";
import AccessTimeIcon from "@mui/icons-material/AccessTime";
import LocationOnIcon from "@mui/icons-material/LocationOn";
import PersonIcon from "@mui/icons-material/Person";
import CalendarTodayIcon from "@mui/icons-material/CalendarToday";

import dayjs from "dayjs";
import "dayjs/locale/fr";

import {
  fetchInstrumentistOffersWithFallback,
  claimMission,
} from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import { useToast } from "../../ui/toast/useToast";
import { useAuth } from "../../auth/AuthContext";
import { isMobileRole } from "../../auth/roles";
import { useNotifications } from "../../features/push/useNotifications";
import { MobileCard } from "../../ui/mobile/MobileCard";
import { requestMissionSync } from "../../features/missions/sync/missionSyncBus";

dayjs.locale("fr");

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

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

// ─── Offer card ─────────────────────────────────────────────────────────────
function OfferCard({
  mission,
  onClaim,
  loading,
  disabled,
}: {
  mission: Mission;
  onClaim: () => void;
  loading: boolean;
  disabled: boolean;
}) {
  const navigate = useNavigate();
  const canClaim = mission.allowedActions?.includes("claim") ?? false;

  const start = mission.startAt ? dayjs(mission.startAt) : null;
  const dateLabel = start
    ? start.format("dddd D MMMM").replace(/^\w/, (c) => c.toUpperCase())
    : "—";
  const timeLine = `${formatTime(mission.startAt)} → ${formatTime(mission.endAt)}`;
  const surgeon = surgeonLabel(mission);

  return (
    <MobileCard
      sx={{ cursor: canClaim ? "default" : "pointer" }}
      onClick={() => !canClaim && navigate(`/app/i/missions/${mission.id}`)}
    >
      <Box sx={{ p: 2 }}>
        <Stack spacing={1.5}>
          {/* Header */}
          <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={1}>
            <Box sx={{ flex: 1, minWidth: 0 }}>
              <Typography variant="subtitle2" noWrap>
                {mission.site?.name ?? "—"}
              </Typography>
            </Box>
            {canClaim && (
              <Button
                variant="contained"
                size="small"
                disableElevation
                disabled={loading || disabled}
                onClick={(e) => {
                  e.stopPropagation();
                  onClaim();
                }}
                sx={{ flexShrink: 0, borderRadius: 1.5, fontWeight: 600 }}
              >
                {loading ? "…" : "Prendre"}
              </Button>
            )}
          </Stack>

          {/* Info rows */}
          <Stack spacing={0.75}>
            <Stack direction="row" spacing={1} alignItems="center">
              <CalendarTodayIcon sx={{ fontSize: 13, color: "text.disabled" }} />
              <Typography variant="body2" color="text.secondary">
                {dateLabel}
              </Typography>
            </Stack>
            <Stack direction="row" spacing={1} alignItems="center">
              <AccessTimeIcon sx={{ fontSize: 13, color: "text.disabled" }} />
              <Typography variant="body2" color="text.secondary">
                {timeLine}
              </Typography>
            </Stack>
            {surgeon && (
              <Stack direction="row" spacing={1} alignItems="center">
                <PersonIcon sx={{ fontSize: 13, color: "text.disabled" }} />
                <Typography variant="body2" color="text.secondary" noWrap>
                  {surgeon}
                </Typography>
              </Stack>
            )}
            {mission.site?.name && (
              <Stack direction="row" spacing={1} alignItems="center">
                <LocationOnIcon sx={{ fontSize: 13, color: "text.disabled" }} />
                <Typography variant="caption" color="text.disabled" noWrap>
                  {mission.site.name}
                </Typography>
              </Stack>
            )}
          </Stack>
        </Stack>
      </Box>
    </MobileCard>
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

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ["missions", "offers"],
    queryFn: () => fetchInstrumentistOffersWithFallback(1, 100),
  });

  const missions = data?.items ?? [];

  const handleClaim = async (mission: Mission) => {
    if (loadingClaimId !== null) return;
    setLoadingClaimId(mission.id);
    try {
      await claimMission(mission.id);
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
      <Stack direction="row" alignItems="center" justifyContent="space-between">
        <Typography variant="h6">
          Offres disponibles
        </Typography>
        {isFetching && (
          <CircularProgress size={16} />
        )}
      </Stack>

      {missions.length === 0 ? (
        <MobileCard sx={{ p: 4, textAlign: "center" }}>
          <Typography variant="body1" color="text.secondary" fontWeight={600}>
            Aucune offre disponible
          </Typography>
          <Typography variant="caption" color="text.disabled">
            Revenez plus tard pour consulter les nouvelles missions
          </Typography>
        </MobileCard>
      ) : (
        missions.map((m) => (
          <OfferCard
            key={m.id}
            mission={m}
            onClaim={() => handleClaim(m)}
            loading={loadingClaimId === m.id}
            disabled={loadingClaimId !== null && loadingClaimId !== m.id}
          />
        ))
      )}
    </Stack>
  );
}
