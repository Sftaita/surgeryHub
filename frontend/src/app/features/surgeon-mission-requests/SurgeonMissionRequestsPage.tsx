import * as React from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { Alert, Box, CircularProgress, Stack, Typography } from "@mui/material";
import dayjs from "dayjs";
import "dayjs/locale/fr";

import { fetchMyMissionRequests } from "./api/surgeonMissionRequests.api";
import type { SurgeonMissionRequest } from "./api/surgeonMissionRequests.types";
import { StatusPill, type StatusPillVariant } from "../../ui/mobile/StatusPill";
import { formatMissionTime } from "../mobile-planning/planningPrimitives";

dayjs.locale("fr");

const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_900 = "#16202B";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}

function typeLabel(type: string): string {
  return type === "CONSULTATION" ? "Consultation" : "Bloc opératoire";
}

function statusInfo(status: SurgeonMissionRequest["status"]): { variant: StatusPillVariant; label: string } {
  if (status === "ACCEPTED") return { variant: "confirmee", label: "Acceptée" };
  if (status === "REJECTED") return { variant: "refusee", label: "Refusée" };
  return { variant: "enAttente", label: "En attente" };
}

// § "Mes demandes" (Lot 5, D-099) — un jour unique affiche juste la date, jamais une
// plage début→fin identique (même souci de clarté que l'affichage des absences).
function dateRangeLabel(startAt: string, endAt: string): string {
  const dateLabel = capitalize(dayjs(startAt).format("D MMMM"));
  return `${dateLabel} · ${formatMissionTime(startAt)}–${formatMissionTime(endAt)}`;
}

function EmptyState({ onAdd }: { onAdd: () => void }) {
  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, px: 2, py: 3, textAlign: "center" }}>
      <Typography sx={{ fontSize: 14, fontWeight: 700, color: GRAY_900 }}>
        Aucune demande de mission
      </Typography>
      <Box
        component="button" type="button" onClick={onAdd}
        sx={{
          mt: "14px", height: 40, px: "18px", border: "none", borderRadius: "10px",
          background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 13.5, fontWeight: 700,
          cursor: "pointer",
        }}
      >
        Nouvelle demande
      </Box>
    </Box>
  );
}

function RequestCard({ request }: { request: SurgeonMissionRequest }) {
  const navigate = useNavigate();
  const status = statusInfo(request.status);

  return (
    <Box sx={{ background: "#fff", borderRadius: "16px", boxShadow: SHADOW_XS, px: 2, py: 1.5 }}>
      <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={1}>
        <Box sx={{ minWidth: 0 }}>
          <Typography sx={{ fontSize: 14, fontWeight: 700, color: GRAY_900 }}>
            {request.site?.name ?? "—"}
          </Typography>
          <Typography sx={{ fontSize: 13, color: GRAY_600, mt: 0.25 }}>
            {dateRangeLabel(request.startAt, request.endAt)}
          </Typography>
          <Typography sx={{ fontSize: 12.5, color: GRAY_500, mt: 0.25 }}>
            {typeLabel(request.type)}
          </Typography>
        </Box>
        <StatusPill variant={status.variant} label={status.label} />
      </Stack>

      {request.status === "REJECTED" && request.reviewComment && (
        <Typography sx={{ fontSize: 13, color: GRAY_600, mt: 1, fontStyle: "italic" }}>
          « {request.reviewComment} »
        </Typography>
      )}

      {request.status === "ACCEPTED" && request.createdMissionId !== null && (
        <Box
          component="button" type="button"
          onClick={() => navigate(`/app/s/missions/${request.createdMissionId}`)}
          sx={{
            mt: 1.25, border: "none", background: "none", color: GREEN_800, fontFamily: "inherit",
            fontWeight: 700, fontSize: 13, cursor: "pointer", p: 0,
          }}
        >
          Voir la mission →
        </Box>
      )}
    </Box>
  );
}

/**
 * Mes demandes (Lot 5, D-099, §17) — deux groupes (En attente / Traitées), jamais une
 * table dense. Une demande PENDING est lecture seule en V1 (§19) : pas d'édition, pas
 * d'annulation, pas de suppression — aucun bouton inactif affiché.
 */
export default function SurgeonMissionRequestsPage() {
  const navigate = useNavigate();

  const requestsQuery = useQuery({
    queryKey: ["surgeon-mission-requests"],
    queryFn: fetchMyMissionRequests,
  });

  const requests = requestsQuery.data ?? [];
  const pending = React.useMemo(
    () => requests.filter((r) => r.status === "PENDING").sort((a, b) => a.startAt.localeCompare(b.startAt)),
    [requests],
  );
  const processed = React.useMemo(
    () => requests.filter((r) => r.status !== "PENDING").sort((a, b) => b.createdAt.localeCompare(a.createdAt)),
    [requests],
  );

  if (requestsQuery.isLoading) {
    return (
      <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
        <CircularProgress size={28} />
      </Box>
    );
  }

  if (requestsQuery.isError) {
    return <Alert severity="error">Impossible de charger vos demandes.</Alert>;
  }

  return (
    <Stack spacing={2}>
      <Stack direction="row" alignItems="center" justifyContent="space-between">
        <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700 }}>
          MES DEMANDES
        </Typography>
        <Box
          component="button" type="button" onClick={() => navigate("/app/s/mission-requests/new")}
          sx={{
            height: 36, px: "14px", border: "none", borderRadius: "10px",
            background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 13, fontWeight: 700,
            cursor: "pointer",
          }}
        >
          + Nouvelle demande
        </Box>
      </Stack>

      {requests.length === 0 ? (
        <EmptyState onAdd={() => navigate("/app/s/mission-requests/new")} />
      ) : (
        <>
          {pending.length > 0 && (
            <Stack spacing={1.25}>
              <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GRAY_500 }}>
                EN ATTENTE
              </Typography>
              <Stack spacing={1.25}>
                {pending.map((r) => <RequestCard key={r.id} request={r} />)}
              </Stack>
            </Stack>
          )}

          {processed.length > 0 && (
            <Stack spacing={1.25}>
              <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GRAY_500 }}>
                TRAITÉES
              </Typography>
              <Stack spacing={1.25}>
                {processed.map((r) => <RequestCard key={r.id} request={r} />)}
              </Stack>
            </Stack>
          )}
        </>
      )}
    </Stack>
  );
}
