import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Box, Button, CircularProgress, Stack, Typography } from "@mui/material";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import dayjs from "dayjs";
import "dayjs/locale/fr";

import { fetchMissionById, getMissionExecution } from "../../features/missions/api/missions.api";
import type { UserRef } from "../../features/missions/api/missions.types";
import { getEncodingBackTarget } from "../../layouts/scrollRestoration";
import { resolveApiAssetUrl } from "../../api/apiAssetUrl";
import { fetchMissionEncoding } from "../../features/encoding/api/encoding.api";
import InterventionsSection from "../../features/encoding/components/InterventionsSection";
import { EncodeHeader } from "../../features/encoding/components/EncodeHeader";
import { MissionReadOnlyCard } from "../../features/encoding/components/MissionReadOnlyCard";
import { WorkedHoursCard } from "../../features/encoding/components/WorkedHoursCard";
import SubmitDialog from "../../features/missions/components/SubmitDialog";
import EditServiceHoursDialog from "../../features/missions/components/EditServiceHoursDialog";
import { SPECIALTIES } from "../../features/planning-manager/api/planning.api";

dayjs.locale("fr");

const GRAY_700 = "#3A4754";
const GRAY_500 = "#727E8C";
const GRAY_150 = "#E7EBEF";

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
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

  // Route mémorisée par MobileLayout au moment d'entrer dans l'encodage (voir
  // scrollRestoration.ts) — jamais navigate(-1), imprévisible sur lien profond ou
  // notification (pourrait sortir de l'app ou atterrir sur une entrée d'historique
  // sans rapport). Repli sur /app/i/today si l'écran a été ouvert directement.
  const handleBack = () => navigate(getEncodingBackTarget());

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

  // Même query key que EditServiceHoursDialog.tsx (mutation) et manager/MissionDetailPage.tsx
  // (lecture) — une seule source de vérité pour les heures prestées, jamais un champ
  // dérivé de ["mission", missionId] (voir formatExecutionHours()).
  const { data: execution } = useQuery({
    queryKey: ["mission-execution", missionId],
    queryFn: () => getMissionExecution(missionId),
    enabled: isValidId && !!mission,
  });

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
        <Button variant="outlined" size="small" startIcon={<ArrowBackIcon />} onClick={handleBack}>
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
        <Button variant="outlined" size="small" startIcon={<ArrowBackIcon />} onClick={handleBack}>
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
        <Button variant="outlined" size="small" startIcon={<ArrowBackIcon />} onClick={handleBack}>
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

  const surgeon = mission.surgeon;
  const specialtyLabel = surgeonSpecialtyLabel(surgeon);
  const surgeonName = surgeon
    ? `Dr. ${[surgeon.firstname, surgeon.lastname].filter(Boolean).join(" ").trim() || surgeon.displayName || surgeon.email}`
    : null;
  const dateLabel = dayjs(mission.startAt).format("dddd D MMMM YYYY").replace(/^\w/, (c) => c.toUpperCase());
  const scheduledLabel = mission.startAt && mission.endAt
    ? `${dayjs(mission.startAt).format("HH[h]mm")} → ${dayjs(mission.endAt).format("HH[h]mm")}`
    : "—";

  return (
    <Box>
      <EncodeHeader
        missionId={mission.id}
        dateLabel={dateLabel}
        typeLabel={missionTypeLabel(mission.type)}
        onBack={handleBack}
        helpTopicId="mission-encoding"
        savedAt={lastSavedAt}
      />

      <Box
        sx={{
          mt: "-28px", mx: "auto", position: "relative",
          display: "flex", flexDirection: "column", gap: "18px",
          width: "100%", maxWidth: 760, padding: "0 16px 20px",
          "@media (min-width:600px)": { maxWidth: 680, gap: "20px", padding: "0 20px 24px" },
          "@media (min-width:900px)": { maxWidth: 720 },
          "@media (min-width:1280px)": { maxWidth: 760, gap: "22px" },
          "@media (max-height:480px) and (orientation:landscape)": { gap: "14px" },
        }}
      >
        <MissionReadOnlyCard
          surgeonName={surgeonName}
          surgeonPhotoUrl={resolveApiAssetUrl(surgeon?.profilePicturePath)}
          specialtyLabel={specialtyLabel}
          siteName={mission.site?.name ?? "—"}
          siteAddress={mission.site?.address ?? null}
          dateLabel={dateLabel}
          scheduledLabel={scheduledLabel}
        />

        {/* Anomalie écran d'encodage (commit dédié) — EncodingStatusPanel (statut +
            signaux de cohérence informationnels + historique commentaires manager)
            formait un résumé intermédiaire redondant avec le véritable récapitulatif
            affiché par SubmitDialog après "Terminer l'encodage". Retiré uniquement de
            cette page : le composant reste utilisé tel quel par
            pages/manager/MissionDetailPage.tsx (composant partagé, jamais modifié). */}

        {/* Zone 1 — "TEMPS DE TRAVAIL" (docs/design/Instruction design/Page encodage
            instrumentiste, DOCUMENTATION.md §1/§2) : bac neutre gris, une seule carte,
            aucune touche de vert — le vert n'appartient qu'aux interventions (zone 2). */}
        <Box
          sx={{
            display: "flex", flexDirection: "column", gap: "14px",
            background: GRAY_150, borderRadius: "16px", padding: "12px 11px 13px",
            "@media (min-width:600px)": { borderRadius: "18px", padding: "13px 13px 14px" },
          }}
        >
          <Box sx={{ display: "flex", alignItems: "center", gap: "9px", padding: "0 3px" }}>
            <Box sx={{ width: 20, height: 20, flexShrink: 0, borderRadius: "6px", background: GRAY_700, color: "#fff", display: "grid", placeItems: "center", fontSize: 11.5, fontWeight: 800 }}>
              1
            </Box>
            <Box sx={{ flex: 1, fontSize: 11.5, fontWeight: 800, letterSpacing: ".1em", textTransform: "uppercase", color: GRAY_700 }}>
              Temps de travail
            </Box>
            <Box sx={{ flexShrink: 0, fontSize: 11.5, fontWeight: 700, color: GRAY_500 }}>1 saisie</Box>
          </Box>
          <WorkedHoursCard execution={execution} canEdit={canEditHours} onOpen={() => setOpenEditHours(true)} />
        </Box>

        {/* Interventions — entries (EPIC Revue instrumentiste, Lot 3, commit 8) est la
            source de vérité du rendu : liste unifiée interventions réelles + drafts,
            déjà triée par orderIndex. `interventions` (legacy) n'est plus transmis que
            pour l'enrichissement Lot 6 (suggestedMaterials/coherence), jamais pour
            construire la liste elle-même. Zone 2 (bac vert + progression + pied de
            validation) est entièrement rendue par InterventionsSection. */}
        <InterventionsSection
          missionId={mission.id}
          canEdit={canEdit}
          entries={encoding.entries ?? []}
          legacyInterventions={encoding.interventions ?? []}
          catalog={encoding.catalog}
          onSaved={() => setLastSavedAt(new Date())}
          canSubmit={canSubmit}
          onValidate={() => setOpenSubmit(true)}
          progress={encoding.progress}
        />
      </Box>

      <SubmitDialog
        open={openSubmit}
        mission={mission}
        onClose={() => setOpenSubmit(false)}
        onSubmitted={() => {
          queryClient.invalidateQueries({ queryKey: ["mission", mission.id] });
          queryClient.invalidateQueries({ queryKey: ["missionEncoding", mission.id] });
          queryClient.invalidateQueries({ queryKey: ["missions"] });
          handleBack();
        }}
        helpTopicId="mission-encoding"
      />

      {canEditHours && openEditHours && (
        <EditServiceHoursDialog
          open={openEditHours}
          onClose={() => setOpenEditHours(false)}
          mission={mission}
          onSaved={() => setLastSavedAt(new Date())}
          helpTopicId="mission-encoding"
        />
      )}
    </Box>
  );
}
