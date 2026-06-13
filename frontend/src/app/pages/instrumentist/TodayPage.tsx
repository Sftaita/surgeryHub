import * as React from "react";
import { useNavigate } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Box,
  Button,
  Chip,
  CircularProgress,
  Divider,
  Stack,
  Typography,
} from "@mui/material";
import AccessTimeIcon from "@mui/icons-material/AccessTime";
import LocationOnIcon from "@mui/icons-material/LocationOn";
import PersonIcon from "@mui/icons-material/Person";
import ArrowForwardIcon from "@mui/icons-material/ArrowForward";
import AddCircleOutlineIcon from "@mui/icons-material/AddCircleOutline";

import dayjs from "dayjs";
import "dayjs/locale/fr";

import {
  fetchMissions,
  fetchInstrumentistOffersWithFallback,
  claimMission,
} from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import { useToast } from "../../ui/toast/useToast";
import { useNotifications } from "../../features/push/useNotifications";
import { useAuth } from "../../auth/AuthContext";
import { MobileCard } from "../../ui/mobile/MobileCard";
import { SectionHeader } from "../../ui/mobile/SectionHeader";

dayjs.locale("fr");

const TODAY_STATUSES = "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED";

function todayRange() {
  const now = new Date();
  const from = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
  const to = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59);
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

type EncodingState = "pending" | "submitted" | "validated" | "none";

function getEncodingState(mission: Mission): EncodingState {
  if (mission.status === "VALIDATED") return "validated";
  if (mission.status === "SUBMITTED") return "submitted";
  if (
    mission.status === "ASSIGNED" ||
    mission.status === "IN_PROGRESS" ||
    mission.status === "DECLARED"
  )
    return "pending";
  return "none";
}

function getEncodingChip(state: EncodingState) {
  if (state === "pending")
    return { label: "Encodage à compléter", color: "warning" as const };
  if (state === "submitted")
    return { label: "En attente de validation", color: "info" as const };
  if (state === "validated")
    return { label: "Validée", color: "success" as const };
  return null;
}

// ─── Hero card (mission du jour) ───────────────────────────────────────────
function TodayMissionHero({ mission }: { mission: Mission }) {
  const navigate = useNavigate();
  const encodingState = getEncodingState(mission);
  const chip = getEncodingChip(encodingState);
  const canEncoding =
    mission.allowedActions?.includes("encoding") ||
    mission.allowedActions?.includes("edit_encoding");

  const timeLine =
    mission.startAt && mission.endAt
      ? `${formatTime(mission.startAt)} → ${formatTime(mission.endAt)}`
      : "—";
  const surgeon = surgeonLabel(mission);

  const isPending = encodingState === "pending";

  return (
    <Box
      sx={{
        borderRadius: 3,
        overflow: "hidden",
        background: isPending
          ? "linear-gradient(135deg, #1976D2 0%, #1565C0 100%)"
          : "linear-gradient(135deg, #388E3C 0%, #2E7D32 100%)",
        color: "#fff",
        p: 2.5,
      }}
    >
      <Stack spacing={2}>
        {/* Top row */}
        <Stack direction="row" alignItems="center" justifyContent="space-between">
          <Typography variant="caption" sx={{ opacity: 0.85, fontWeight: 600, textTransform: "uppercase", letterSpacing: 0.5 }}>
            Mission du jour
          </Typography>
          {chip && (
            <Chip
              label={chip.label}
              size="small"
              sx={{
                bgcolor: "rgba(255,255,255,0.2)",
                color: "#fff",
                fontWeight: 600,
                fontSize: "0.7rem",
                height: 22,
              }}
            />
          )}
        </Stack>

        {/* Time */}
        <Box>
          <Typography variant="h4" fontWeight={800} lineHeight={1}>
            {timeLine}
          </Typography>
        </Box>

        {/* Info */}
        <Stack spacing={0.5}>
          <Stack direction="row" spacing={0.75} alignItems="center">
            <LocationOnIcon sx={{ fontSize: 14, opacity: 0.8 }} />
            <Typography variant="body2" sx={{ opacity: 0.9 }}>
              {mission.site?.name ?? "—"}
            </Typography>
          </Stack>
          {surgeon && (
            <Stack direction="row" spacing={0.75} alignItems="center">
              <PersonIcon sx={{ fontSize: 14, opacity: 0.8 }} />
              <Typography variant="body2" sx={{ opacity: 0.9 }}>
                {surgeon}
              </Typography>
            </Stack>
          )}
        </Stack>

        {/* CTA */}
        <Button
          variant="contained"
          disableElevation
          fullWidth
          endIcon={<ArrowForwardIcon />}
          onClick={() =>
            navigate(
              canEncoding
                ? `/app/i/missions/${mission.id}/encoding`
                : `/app/i/missions/${mission.id}`
            )
          }
          sx={{
            bgcolor: "rgba(255,255,255,0.2)",
            color: "#fff",
            fontWeight: 700,
            borderRadius: 2,
            "&:hover": { bgcolor: "rgba(255,255,255,0.3)" },
          }}
        >
          {canEncoding ? "Encoder la mission" : "Voir la mission"}
        </Button>
      </Stack>
    </Box>
  );
}

// ─── No mission state ───────────────────────────────────────────────────────
function NoMissionCard() {
  return (
    <MobileCard sx={{ p: 3, textAlign: "center" }}>
      <Typography variant="body1" fontWeight={600} color="text.secondary" mb={0.5}>
        Aucune mission aujourd'hui
      </Typography>
      <Typography variant="caption" color="text.disabled">
        Consultez les offres disponibles ci-dessous
      </Typography>
    </MobileCard>
  );
}

// ─── Offer card (carousel) ──────────────────────────────────────────────────
function OfferCard({ mission }: { mission: Mission }) {
  const navigate = useNavigate();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { addNotification } = useNotifications();
  const [loading, setLoading] = React.useState(false);

  const canClaim = mission.allowedActions?.includes("claim") ?? false;

  const start = mission.startAt ? dayjs(mission.startAt) : null;
  const dateLabel = start
    ? start.format("ddd D MMM").replace(/^\w/, (c) => c.toUpperCase())
    : "—";
  const timeLine = `${formatTime(mission.startAt)} → ${formatTime(mission.endAt)}`;

  const handleClaim = async (e: React.MouseEvent) => {
    e.stopPropagation();
    if (loading) return;
    setLoading(true);
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
    } catch (err: any) {
      if (err?.response?.status === 409) {
        toast.warning("Cette mission vient d'être prise par quelqu'un d'autre.");
        queryClient.invalidateQueries({ queryKey: ["missions"] });
      } else {
        toast.error("Impossible de prendre cette mission.");
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <MobileCard
      sx={{ minWidth: 200, flexShrink: 0, cursor: "pointer" }}
      onClick={() => !canClaim && navigate(`/app/i/missions/${mission.id}`)}
    >
      <Box sx={{ p: 1.75 }}>
        <Stack spacing={0.75}>
          <Typography variant="caption" fontWeight={700} color="primary.main" noWrap>
            {mission.site?.name ?? "—"}
          </Typography>
          <Typography variant="body2" fontWeight={600} noWrap>
            {dateLabel}
          </Typography>
          <Stack direction="row" spacing={0.5} alignItems="center">
            <AccessTimeIcon sx={{ fontSize: 12, color: "text.disabled" }} />
            <Typography variant="caption" color="text.secondary">
              {timeLine}
            </Typography>
          </Stack>
          {canClaim && (
            <Button
              variant="contained"
              size="small"
              disableElevation
              disabled={loading}
              onClick={handleClaim}
              sx={{ mt: 0.5, borderRadius: 1.5, fontWeight: 600 }}
            >
              {loading ? "…" : "Prendre"}
            </Button>
          )}
        </Stack>
      </Box>
    </MobileCard>
  );
}

// ─── Main page ──────────────────────────────────────────────────────────────
export default function TodayPage() {
  const navigate = useNavigate();
  const { state } = useAuth();
  const { from, to } = React.useMemo(() => todayRange(), []);

  const firstName =
    state.status === "authenticated" ? state.user.firstname ?? null : null;

  const dateLabel = dayjs()
    .format("dddd D MMMM")
    .replace(/^\w/, (c) => c.toUpperCase());

  const { data: todayData, isLoading: loadingToday } = useQuery({
    queryKey: ["missions", "today", { from, to }],
    queryFn: () =>
      fetchMissions(1, 10, {
        assignedToMe: true,
        status: TODAY_STATUSES,
        from,
        to,
      }),
    refetchInterval: 60_000,
  });

  const { data: offersData, isLoading: loadingOffers } = useQuery({
    queryKey: ["missions", "offers"],
    queryFn: () => fetchInstrumentistOffersWithFallback(1, 4),
    refetchInterval: 60_000,
  });

  const todayMissions = todayData?.items ?? [];
  const offers = offersData?.items ?? [];
  const mainMission = todayMissions[0] ?? null;

  return (
    <Stack spacing={2.5}>
      {/* Greeting */}
      <Box>
        <Typography variant="h6" fontWeight={800}>
          {firstName ? `Bonjour, ${firstName} !` : "Bonjour !"}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {dateLabel}
        </Typography>
      </Box>

      {/* Mission du jour */}
      {loadingToday ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}>
          <CircularProgress size={28} />
        </Box>
      ) : mainMission ? (
        <TodayMissionHero mission={mainMission} />
      ) : (
        <NoMissionCard />
      )}

      {/* Déclarer */}
      <Button
        variant="outlined"
        fullWidth
        startIcon={<AddCircleOutlineIcon />}
        onClick={() => navigate("/app/i/missions/declare")}
        sx={{ borderRadius: 2, fontWeight: 600 }}
      >
        Déclarer une mission non prévue
      </Button>

      <Divider />

      {/* Offres disponibles */}
      <Box>
        <SectionHeader title="Offres disponibles" />

        {loadingOffers ? (
          <CircularProgress size={20} />
        ) : offers.length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            Aucune offre disponible pour le moment.
          </Typography>
        ) : (
          <Box
            sx={{
              display: "flex",
              gap: 1.5,
              overflowX: "auto",
              pb: 1,
              mx: -1.5,
              px: 1.5,
              scrollbarWidth: "none",
              "&::-webkit-scrollbar": { display: "none" },
            }}
          >
            {offers.map((m) => (
              <OfferCard key={m.id} mission={m} />
            ))}
          </Box>
        )}
      </Box>
    </Stack>
  );
}
