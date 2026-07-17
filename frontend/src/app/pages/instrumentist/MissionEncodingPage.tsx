import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Box, Button, CircularProgress, Stack, Typography } from "@mui/material";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import dayjs from "dayjs";
import "dayjs/locale/fr";

import { fetchMissionById } from "../../features/missions/api/missions.api";
import type { UserRef } from "../../features/missions/api/missions.types";
import { fetchMissionEncoding } from "../../features/encoding/api/encoding.api";
import InterventionsSection from "../../features/encoding/components/InterventionsSection";
import { EncodeHeader } from "../../features/encoding/components/EncodeHeader";
import SubmitDialog from "../../features/missions/components/SubmitDialog";
import EditServiceHoursDialog from "../../features/missions/components/EditServiceHoursDialog";
import { SPECIALTIES } from "../../features/planning-manager/api/planning.api";

dayjs.locale("fr");

const GREEN_50 = "#EFFAF5";
const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GRAY_300 = "#C2C9D1";
const GRAY_400 = "#98A2AE";
const GRAY_700 = "#3A4754";
const SHADOW_MD = "0 2px 6px rgba(22,32,43,.06), 0 8px 20px rgba(22,32,43,.08)";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";
const SHADOW_SM = "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)";

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

function formatHours(hours?: string | number | null): string {
  if (hours === null || hours === undefined || hours === "") return "Non renseigné";
  const n = typeof hours === "string" ? Number(hours) : hours;
  if (!Number.isFinite(n)) return "Non renseigné";
  return `${n} h`;
}

function missionTypeLabel(type: string): string {
  return type === "CONSULTATION" ? "Consultation" : "Bloc opératoire";
}

/**
 * Aucune notion de "spécialité principale" n'existe dans le modèle (User.specialties
 * est un tableau plat, sans ordre de priorité ni champ dédié) — on prend donc la
 * première spécialité renvoyée par l'API, jamais une sélection arbitraire. Absence de
 * spécialité => pas de suffixe (jamais "· undefined").
 */
function surgeonSpecialtyLabel(surgeon: UserRef | null | undefined): string | null {
  const value = surgeon?.specialties?.[0];
  if (!value) return null;
  return SPECIALTIES.find((s) => s.value === value)?.label ?? value;
}

function ClockIcon() {
  return (
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke={GREEN_700} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
    </svg>
  );
}
function ChevronRightIcon() {
  return (
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke={GRAY_300} strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
      <path d="m9 18 6-6-6-6" />
    </svg>
  );
}

export default function MissionEncodingPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [openSubmit, setOpenSubmit] = React.useState(false);
  const [openEditHours, setOpenEditHours] = React.useState(false);
  // Aucun timestamp de sauvegarde n'existe côté backend (MissionEncodingResponse
  // n'en a pas) — reflète la dernière mutation réussie observée sur cet appareil,
  // pas une donnée serveur.
  const [lastSavedAt, setLastSavedAt] = React.useState<Date | null>(null);

  const missionId = Number(id);
  const isValidId = Number.isFinite(missionId) && missionId > 0;

  const {
    data: mission,
    isLoading: isMissionLoading,
    error: missionError,
  } = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: isValidId,
  });

  const canEncoding =
    mission?.allowedActions?.includes("encoding") ||
    mission?.allowedActions?.includes("edit_encoding");

  const canSubmit = mission?.allowedActions?.includes("submit") ?? false;
  const canEditHours = mission?.allowedActions?.includes("edit_hours") ?? false;

  const {
    data: encoding,
    isLoading: isEncodingLoading,
    error: encodingError,
  } = useQuery({
    queryKey: ["missionEncoding", missionId],
    queryFn: () => fetchMissionEncoding(missionId),
    enabled: isValidId && !!mission && !!canEncoding,
  });

  if (!isValidId) return <Typography>Identifiant invalide</Typography>;
  if (isMissionLoading) return <CircularProgress />;

  if (missionError) {
    return (
      <Stack spacing={2}>
        <Button variant="outlined" size="small" startIcon={<ArrowBackIcon />} onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography color="error">{extractErrorMessage(missionError)}</Typography>
      </Stack>
    );
  }

  if (!mission) return <Typography>Mission introuvable</Typography>;

  if (!canEncoding) {
    return (
      <Stack spacing={2}>
        <Button variant="outlined" size="small" startIcon={<ArrowBackIcon />} onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography color="text.secondary">
          L'encodage n'est pas disponible pour cette mission.
        </Typography>
      </Stack>
    );
  }

  if (isEncodingLoading) return <CircularProgress />;

  if (encodingError) {
    return (
      <Stack spacing={2}>
        <Button variant="outlined" size="small" startIcon={<ArrowBackIcon />} onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography color="error">{extractErrorMessage(encodingError)}</Typography>
      </Stack>
    );
  }

  if (!encoding) return <Typography>Données d'encodage introuvables</Typography>;

  const canEdit =
    encoding.mission?.allowedActions?.includes("encoding") ||
    encoding.mission?.allowedActions?.includes("edit_encoding") ||
    false;

  const hoursLabel = formatHours(mission.service?.hours ?? null);
  const surgeon = mission.surgeon;
  const specialtyLabel = surgeonSpecialtyLabel(surgeon);
  const personLine = surgeon
    ? `Dr. ${[surgeon.firstname, surgeon.lastname].filter(Boolean).join(" ").trim() || surgeon.displayName || surgeon.email}${specialtyLabel ? ` · ${specialtyLabel}` : ""}`
    : null;

  const interventionCount = encoding.interventions?.length ?? 0;
  const materialCount = (encoding.interventions ?? []).reduce(
    (sum, itv) => sum + (itv.materialLines?.length ?? 0), 0,
  );
  const countsLabel = `${interventionCount} intervention${interventionCount > 1 ? "s" : ""} · ${materialCount} matériel${materialCount > 1 ? "s" : ""}`;

  return (
    <Box>
      <EncodeHeader
        missionId={mission.id}
        siteName={mission.site?.name ?? "—"}
        personLine={personLine}
        dateLabel={dayjs(mission.startAt).format("dddd D MMMM YYYY").replace(/^\w/, (c) => c.toUpperCase())}
        typeLabel={missionTypeLabel(mission.type)}
        onBack={() => navigate(-1)}
      />

      <Box sx={{ px: "20px", mt: "-28px", position: "relative", display: "flex", flexDirection: "column", gap: "14px" }}>
        {/* Barre brouillon */}
        <Stack
          direction="row"
          alignItems="center"
          spacing={1.5}
          sx={{ background: "#fff", borderRadius: "14px", padding: "12px 15px", boxShadow: SHADOW_MD }}
        >
          <Box
            sx={{
              width: 9, height: 9, borderRadius: "999px", background: GREEN_500, flexShrink: 0,
              animation: "shPulse 1.6s ease-in-out infinite",
              "@keyframes shPulse": { "0%,100%": { opacity: 1 }, "50%": { opacity: 0.35 } },
            }}
          />
          <Box sx={{ flex: 1, minWidth: 0 }}>
            <Box sx={{ fontSize: 13.5, fontWeight: 700, color: GREEN_700 }}>Brouillon en cours</Box>
            <Box sx={{ mt: "1px", fontSize: 12.5, color: GRAY_400, fontVariantNumeric: "tabular-nums" }}>{countsLabel}</Box>
          </Box>
          {lastSavedAt && (
            <Box sx={{ display: "flex", alignItems: "center", gap: "6px", fontSize: 12, color: GRAY_400, flexShrink: 0, fontVariantNumeric: "tabular-nums" }}>
              Enregistré à {dayjs(lastSavedAt).format("HH:mm")}
            </Box>
          )}
        </Stack>

        {/* Heures prestées */}
        <Box
          component="button"
          type="button"
          onClick={() => canEditHours && setOpenEditHours(true)}
          sx={{
            display: "flex", alignItems: "center", gap: "12px", background: "#fff", border: "none", borderRadius: "16px",
            padding: "14px 16px", boxShadow: SHADOW_XS, cursor: canEditHours ? "pointer" : "default", fontFamily: "inherit",
            textAlign: "left", width: "100%", transition: "box-shadow 150ms",
            "&:hover": canEditHours ? { boxShadow: SHADOW_SM } : undefined,
          }}
        >
          <Box sx={{ width: 38, height: 38, borderRadius: "999px", background: GREEN_50, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
            <ClockIcon />
          </Box>
          <Box sx={{ flex: 1, minWidth: 0, display: "flex", flexDirection: "column" }}>
            <Box sx={{ fontSize: 13, fontWeight: 700, color: GRAY_700 }}>Heures prestées</Box>
            <Box sx={{ fontSize: 15, fontWeight: 800, color: hoursLabel === "Non renseigné" ? GRAY_400 : GREEN_700 }}>
              {hoursLabel}
            </Box>
          </Box>
          {canEditHours && <ChevronRightIcon />}
        </Box>

        {/* Interventions */}
        <InterventionsSection
          missionId={mission.id}
          canEdit={canEdit}
          interventions={encoding.interventions ?? []}
          catalog={encoding.catalog}
          onSaved={() => setLastSavedAt(new Date())}
        />

        {canSubmit && (
          <Button
            variant="contained"
            disableElevation
            fullWidth
            onClick={() => setOpenSubmit(true)}
            sx={{
              height: 54, borderRadius: "13px", fontWeight: 700, fontSize: 15.5, textTransform: "none",
              bgcolor: "#1F6B4F", boxShadow: "0 5px 14px rgba(20,77,56,.3)", "&:hover": { bgcolor: "#144D38" },
            }}
          >
            Terminer l'encodage
          </Button>
        )}
      </Box>

      <SubmitDialog
        open={openSubmit}
        mission={mission}
        onClose={() => setOpenSubmit(false)}
        onSubmitted={() => {
          queryClient.invalidateQueries({ queryKey: ["mission", mission.id] });
          queryClient.invalidateQueries({ queryKey: ["missionEncoding", mission.id] });
          queryClient.invalidateQueries({ queryKey: ["missions"] });
          navigate(-1);
        }}
      />

      {canEditHours && openEditHours && (
        <EditServiceHoursDialog
          open={openEditHours}
          onClose={() => setOpenEditHours(false)}
          mission={mission}
        />
      )}
    </Box>
  );
}
