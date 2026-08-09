import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { Box, Dialog, DialogTitle, DialogContent, Stack, Typography, CircularProgress, Alert } from "@mui/material";
import dayjs from "dayjs";
import "dayjs/locale/fr";

import { fetchMissions } from "../../features/missions/api/missions.api";
import { MissionDetailContent } from "../instrumentist/MissionDetailPage";
import type { Mission } from "../../features/missions/api/missions.types";
import { compareMissionsByStart, formatMissionTime } from "../../features/mobile-planning/planningPrimitives";
import { useSurgeonActivity } from "../../features/surgeon-activity/useSurgeonActivity";
import { todayYmd } from "../../features/surgeon-activity/period";
import { PodiumRows } from "../../features/surgeon-activity/components/PodiumRows";
import type { SurgeonActivityIntervention } from "../../features/surgeon-activity/api/surgeonActivity.types";

dayjs.locale("fr");

const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_900 = "#16202B";
const AMBER_700 = "#B7791F";
const AMBER_50 = "#FEF6E7";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

// Statuts "réellement planifiés" pour la Home — même périmètre que le planning
// chirurgien (SurgeonPlanningPage), CANCELLED inclus pour rester cohérent (visible,
// mais exclu de tous les comptages "résumé du mois" ci-dessous, comme
// PlanningCoverageService::computeForVersion() exclut CANCELLED de `total`).
const SURGEON_HOME_STATUSES = "OPEN,ASSIGNED,SUBMITTED,VALIDATED,CLOSED,IN_PROGRESS,CANCELLED";

// Fenêtre "à venir" volontairement limitée (pas de nouvel endpoint dédié "prochaine
// mission" — réutilise le listing existant, voir Lot 2 D-095) : suffisante en
// pratique pour trouver la prochaine mission réelle sans charger tout l'historique.
const UPCOMING_WINDOW_DAYS = 90;

function isLive(mission: Mission): boolean {
  return mission.status !== "CANCELLED";
}

function isUncovered(mission: Mission): boolean {
  return mission.status === "OPEN";
}

function startOfCurrentMonthIso(): string {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), 1).toISOString();
}

function startOfNextMonthIso(): string {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth() + 1, 1).toISOString();
}

function getInstrumentistLabel(mission: Mission): string {
  const i = mission.instrumentist;
  if (!i) return "À couvrir";
  const displayName = i.displayName?.trim();
  if (displayName) return displayName;
  const fn = (i.firstname ?? "").trim();
  const ln = (i.lastname ?? "").trim();
  const full = `${fn} ${ln}`.trim();
  if (full) return full;
  return i.email || "—";
}

function formatMissionDate(iso: string): string {
  const raw = dayjs(iso).format("dddd D MMMM");
  return raw.charAt(0).toUpperCase() + raw.slice(1);
}

// ── Prochaine mission ────────────────────────────────────────────────────────
function NextMissionCard({ mission, onOpen }: { mission: Mission; onOpen: () => void }) {
  const covered = mission.covered === true;
  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, overflow: "hidden" }}>
      <Box sx={{ px: 2, pt: 1.75, pb: 0.5 }}>
        <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700 }}>
          PROCHAINE MISSION
        </Typography>
      </Box>
      <Box sx={{ px: 2, pb: 2 }}>
        <Typography sx={{ fontSize: 17, fontWeight: 800, color: GRAY_900 }}>
          {formatMissionDate(mission.startAt)}
        </Typography>
        <Typography sx={{ fontSize: 14, color: GRAY_600, mt: 0.25 }}>
          {mission.site?.name ?? "—"} · {formatMissionTime(mission.startAt)} – {formatMissionTime(mission.endAt)}
        </Typography>

        <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mt: 1.75, pt: 1.5, borderTop: "1px dashed", borderColor: "grey.200" }}>
          <Typography sx={{ fontSize: 12.5, color: GRAY_500 }}>Instrumentiste</Typography>
          <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: covered ? GRAY_900 : AMBER_700 }}>
            {getInstrumentistLabel(mission)}
          </Typography>
        </Stack>

        <Box
          component="button"
          type="button"
          onClick={onOpen}
          sx={{
            mt: 1.75, width: "100%", height: 44, border: "none", borderRadius: "12px",
            background: GREEN_800, color: "#fff", fontFamily: "inherit", fontWeight: 700, fontSize: 14,
            cursor: "pointer",
          }}
        >
          Voir la mission
        </Box>
      </Box>
    </Box>
  );
}

function NoUpcomingMissionCard() {
  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, px: 2, py: 2.5, textAlign: "center" }}>
      <Typography sx={{ fontSize: 13.5, color: GRAY_600 }}>
        Aucune mission planifiée prochainement
      </Typography>
    </Box>
  );
}

// ── Résumé du mois ───────────────────────────────────────────────────────────
function MonthSummaryCard({ total, covered, uncovered }: { total: number; covered: number; uncovered: number }) {
  const monthLabel = dayjs().format("MMMM").toUpperCase();
  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, px: 2, py: 1.75 }}>
      <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700, mb: 1.25 }}>
        {monthLabel}
      </Typography>
      <Stack direction="row" spacing={2.5}>
        <Box>
          <Typography sx={{ fontSize: 22, fontWeight: 800, color: GRAY_900, lineHeight: 1 }}>{total}</Typography>
          <Typography sx={{ fontSize: 12, color: GRAY_500, mt: 0.375 }}>mission{total > 1 ? "s" : ""}</Typography>
        </Box>
        <Box>
          <Typography sx={{ fontSize: 22, fontWeight: 800, color: GREEN_700, lineHeight: 1 }}>{covered}</Typography>
          <Typography sx={{ fontSize: 12, color: GRAY_500, mt: 0.375 }}>couverte{covered > 1 ? "s" : ""}</Typography>
        </Box>
        <Box>
          <Typography sx={{ fontSize: 22, fontWeight: 800, color: uncovered > 0 ? AMBER_700 : GRAY_900, lineHeight: 1 }}>{uncovered}</Typography>
          <Typography sx={{ fontSize: 12, color: GRAY_500, mt: 0.375 }}>à couvrir</Typography>
        </Box>
      </Stack>
    </Box>
  );
}

// ── À suivre ─────────────────────────────────────────────────────────────────
// ── CTA demande de mission (Lot 5, D-099) ───────────────────────────────────
// Toujours visible, discrète (bordure pointillée, pas un bouton plein) — accessible
// sans être envahissante (§14). Même CTA sur Home et Planning, jamais une 6e entrée
// navbar.
function RequestMissionCta() {
  const navigate = useNavigate();
  return (
    <Box
      component="button" type="button" onClick={() => navigate("/app/s/mission-requests/new")}
      sx={{
        width: "100%", height: 46, border: "1.5px dashed", borderColor: "grey.300", borderRadius: "13px",
        background: "transparent", color: GREEN_800, fontFamily: "inherit", fontSize: 13.5, fontWeight: 700,
        cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center", gap: "6px",
      }}
    >
      + Demander une mission
    </Box>
  );
}

function FollowUpCard({ upcomingUncoveredCount }: { upcomingUncoveredCount: number }) {
  return (
    <Box sx={{ background: AMBER_50, borderRadius: "14px", px: 2, py: 1.5 }}>
      <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: AMBER_700 }}>
        {upcomingUncoveredCount} mission{upcomingUncoveredCount > 1 ? "s" : ""} à couvrir prochainement
      </Typography>
    </Box>
  );
}

// ── Podium activité (Lot 4, D-098) ──────────────────────────────────────────
// Réutilise PodiumRows (même composant que SurgeonActivityPage) et useSurgeonActivity avec
// les mêmes défauts (année en cours) que la page Activity ouverte sans paramètres d'URL —
// même queryKey, donc même cache React Query, jamais un second calcul côté Home (§12).
function ActivityPodiumCard({ interventions, year }: { interventions: SurgeonActivityIntervention[]; year: string }) {
  const navigate = useNavigate();
  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, px: 2, py: 1.75 }}>
      <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700, mb: 1.25 }}>
        MES INTERVENTIONS — {year}
      </Typography>
      <PodiumRows interventions={interventions} showHeading={false} />
      <Box
        component="button" type="button" onClick={() => navigate("/app/s/activity")}
        sx={{ mt: 1.5, border: "none", background: "none", color: GREEN_800, fontFamily: "inherit", fontWeight: 700, fontSize: 13, cursor: "pointer", p: 0 }}
      >
        Voir toute l'activité →
      </Box>
    </Box>
  );
}

export default function SurgeonHomePage() {
  const [selectedMissionId, setSelectedMissionId] = React.useState<number | null>(null);

  const upcomingFrom = React.useMemo(() => new Date().toISOString(), []);
  const upcomingTo = React.useMemo(
    () => new Date(Date.now() + UPCOMING_WINDOW_DAYS * 24 * 60 * 60 * 1000).toISOString(),
    [],
  );

  // Pas de assignedToMe:true — /api/missions self-scope déjà m.surgeon = self pour un
  // appelant ROLE_SURGEON sans ce paramètre (voir SurgeonPlanningPage). Deux fenêtres
  // de requête distinctes plutôt qu'un nouvel endpoint dédié "Home" (Lot 2, D-095) :
  // "à venir" (large, pour trouver la prochaine mission réelle) et "ce mois-ci" (résumé).
  const upcomingQuery = useQuery({
    queryKey: ["missions", "surgeon-home-upcoming", { from: upcomingFrom, to: upcomingTo }],
    queryFn: () => fetchMissions(1, 50, { from: upcomingFrom, to: upcomingTo, status: SURGEON_HOME_STATUSES }),
  });

  const monthFrom = React.useMemo(() => startOfCurrentMonthIso(), []);
  const monthTo = React.useMemo(() => startOfNextMonthIso(), []);
  const monthQuery = useQuery({
    queryKey: ["missions", "surgeon-home-month", { from: monthFrom, to: monthTo }],
    queryFn: () => fetchMissions(1, 200, { from: monthFrom, to: monthTo, status: SURGEON_HOME_STATUSES }),
  });

  const isLoading = upcomingQuery.isLoading || monthQuery.isLoading;
  const isError = upcomingQuery.isError || monthQuery.isError;

  const nextMission = React.useMemo(() => {
    const items = upcomingQuery.data?.items ?? [];
    const nowMs = Date.now();
    return items
      .filter(isLive)
      .filter((m) => new Date(m.startAt).getTime() > nowMs)
      .sort(compareMissionsByStart)[0] ?? null;
  }, [upcomingQuery.data]);

  const upcomingUncoveredCount = React.useMemo(() => {
    const items = upcomingQuery.data?.items ?? [];
    const nowMs = Date.now();
    return items.filter(isLive).filter(isUncovered).filter((m) => new Date(m.startAt).getTime() > nowMs).length;
  }, [upcomingQuery.data]);

  const monthSummary = React.useMemo(() => {
    const items = (monthQuery.data?.items ?? []).filter(isLive);
    return {
      total: items.length,
      covered: items.filter((m) => m.covered === true).length,
      uncovered: items.filter(isUncovered).length,
    };
  }, [monthQuery.data]);

  // Année en cours, mêmes défauts que SurgeonActivityPage ouverte sans paramètres d'URL —
  // même queryKey côté useSurgeonActivity, donc même cache (§12, Lot 4, D-098).
  const currentYear = React.useMemo(() => todayYmd(), []);
  const activityQuery = useSurgeonActivity("year", currentYear);
  const topInterventions = activityQuery.data?.interventions ?? [];

  if (isLoading) {
    return (
      <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
        <CircularProgress size={28} />
      </Box>
    );
  }

  if (isError) {
    return <Alert severity="error">Impossible de charger votre accueil.</Alert>;
  }

  return (
    <Stack spacing={2}>
      {nextMission ? (
        <NextMissionCard mission={nextMission} onOpen={() => setSelectedMissionId(nextMission.id)} />
      ) : (
        <NoUpcomingMissionCard />
      )}

      <MonthSummaryCard total={monthSummary.total} covered={monthSummary.covered} uncovered={monthSummary.uncovered} />

      {/* À suivre — uniquement si quelque chose mérite l'attention, jamais une grande empty card. */}
      {upcomingUncoveredCount > 0 && <FollowUpCard upcomingUncoveredCount={upcomingUncoveredCount} />}

      <RequestMissionCta />

      {/* Podium activité (Lot 4, D-098) — jamais une grande section vide tant qu'aucune
          intervention validée n'existe (§12). */}
      {topInterventions.length > 0 && (
        <ActivityPodiumCard interventions={topInterventions} year={currentYear.slice(0, 4)} />
      )}

      <Dialog
        open={selectedMissionId !== null}
        onClose={() => setSelectedMissionId(null)}
        fullWidth
        maxWidth="md"
      >
        <DialogTitle>Détail mission</DialogTitle>
        <DialogContent dividers>
          {selectedMissionId ? (
            <MissionDetailContent
              missionId={selectedMissionId}
              embedded
              onCloseEmbedded={() => setSelectedMissionId(null)}
            />
          ) : null}
        </DialogContent>
      </Dialog>
    </Stack>
  );
}
