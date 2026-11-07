import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Alert, Box, Chip, CircularProgress, IconButton, Stack, Typography } from "@mui/material";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import dayjs from "dayjs";

import { fetchMissionById, getMissionExecution } from "../missions/api/missions.api";
import { formatMissionStatus } from "../missions/utils/missions.format";
import { fetchMissionEncoding } from "../encoding/api/encoding.api";
import InterventionsSection from "../encoding/components/InterventionsSection";
import SubmitDialog from "../missions/components/SubmitDialog";
import { EncodingHoursSummary } from "./components/EncodingHoursSummary";
import { ReadOnlyInterventionCard } from "./components/ReadOnlyInterventionCard";
import { AnomalyReportSection } from "../encoding-anomaly-reports/components/AnomalyReportSection";

const GRAY_500 = "#727E8C";
const GRAY_900 = "#16202B";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

const IN_PROGRESS_STATUSES = ["ASSIGNED", "IN_PROGRESS", "ENCODING_IN_PROGRESS", "DECLARED"];

/**
 * D-143 — la synthèse n'est jamais présentée comme définitive à tort : encore en cours
 * (provisoire), soumise (date), en cours de correction après une soumission (ne plus
 * s'y fier), validée. Dérivé du statut serveur et de submittedAt, rien d'autre.
 */
export function encodingStateBanner(
  status: string,
  submittedAt: string | null | undefined,
  withoutInstrumentist = false,
): { severity: "info" | "success" | "warning"; text: string } | null {
  // D-155 — activité déclarée sans instrumentiste : c'est le chirurgien qui encode et soumet.
  if (withoutInstrumentist && IN_PROGRESS_STATUSES.includes(status)) {
    return submittedAt
      ? { severity: "warning", text: "Encodage renvoyé en correction par la coordination : corrigez-le puis soumettez-le à nouveau." }
      : { severity: "info", text: "Activité réalisée sans instrumentiste : encodez les interventions et le matériel, puis soumettez l'encodage." };
  }
  if (status === "SUBMITTED") {
    return { severity: "success", text: submittedAt ? `Encodage soumis le ${dayjs(submittedAt).format("DD/MM/YYYY [à] HH[h]mm")}.` : "Encodage soumis." };
  }
  if (status === "VALIDATED" || status === "CLOSED") {
    return { severity: "success", text: "Encodage soumis et validé par la coordination." };
  }
  if (IN_PROGRESS_STATUSES.includes(status)) {
    return submittedAt
      ? { severity: "warning", text: "Encodage en cours de correction par l'instrumentiste — les informations ci-dessous peuvent encore changer." }
      : { severity: "info", text: "Encodage en cours — informations provisoires, pas encore soumises par l'instrumentiste." };
  }
  return null;
}

/**
 * Lot 6 (D-100) — consultation chirurgien de l'encodage réalisé par l'instrumentiste.
 * Réutilise GET /api/missions/{id}/encoding tel quel (VIEW_ENCODING, §3) : aucun
 * endpoint parallèle. Sans droit d'écriture : présentation strictement lecture — aucun
 * composant d'édition réutilisé avec ses boutons masqués (§8-9), cartes dédiées.
 *
 * D-154 — quand le backend sert `edit_encoding` (sa propre mission, commencée, encodage
 * ouvert), le chirurgien ajoute, modifie et supprime interventions et matériel avec la
 * section d'encodage de l'instrumentiste (mêmes endpoints, règles, verrous et contrôle
 * de version). Jamais les heures (EncodingHoursSummary reste en lecture), jamais de
 * démarrage ni de soumission. Seul `edit_encoding` pilote ce mode : la mission sans
 * instrumentiste (L6) le réutilisera tel quel.
 */
export default function SurgeonEncodingPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const missionId = Number(id);
  const isValidId = Number.isFinite(missionId) && missionId > 0;

  const handleBack = () => navigate(`/app/s/missions/${missionId}`);

  const { data: mission, isLoading: isMissionLoading } = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: isValidId,
  });

  const canView = mission?.allowedActions?.includes("view_encoding") ?? false;
  const canEdit = mission?.allowedActions?.includes("edit_encoding") ?? false;
  // D-155 — le chirurgien soumet lui-même l'activité qu'il a déclarée sans instrumentiste.
  const canSubmit = mission?.allowedActions?.includes("submit") ?? false;
  const withoutInstrumentist = mission?.executedWithoutInstrumentist != null;
  const [submitOpen, setSubmitOpen] = React.useState(false);
  const queryClient = useQueryClient();

  const {
    data: encoding,
    isLoading: isEncodingLoading,
    isError: isEncodingError,
    refetch,
  } = useQuery({
    queryKey: ["missionEncoding", missionId],
    queryFn: () => fetchMissionEncoding(missionId),
    enabled: isValidId && !!mission && canView,
  });

  const { data: execution } = useQuery({
    queryKey: ["mission-execution", missionId],
    queryFn: () => getMissionExecution(missionId),
    enabled: isValidId && !!mission && canView && !withoutInstrumentist,
  });

  if (!isValidId) return <Typography>Identifiant invalide</Typography>;

  const isLoading = isMissionLoading || (canView && isEncodingLoading);

  return (
    <Stack spacing={2}>
      <Stack direction="row" alignItems="center" spacing={1}>
        <IconButton size="small" onClick={handleBack} aria-label="Retour">
          <ArrowBackIcon fontSize="small" />
        </IconButton>
        <Typography variant="subtitle1" sx={{ flex: 1 }}>
          Encodage — Mission #{missionId}
        </Typography>
        {mission && <Chip label={formatMissionStatus(mission.status)} size="small" />}
      </Stack>

      {isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
          <CircularProgress size={28} />
        </Box>
      ) : !mission || !canView ? (
        <Alert severity="warning">
          La consultation de l'encodage n'est pas disponible pour cette mission.
        </Alert>
      ) : isEncodingError ? (
        <Alert
          severity="error"
          action={
            <Box
              component="button"
              type="button"
              onClick={() => refetch()}
              sx={{ border: "none", background: "none", cursor: "pointer", fontWeight: 700, fontFamily: "inherit", color: "inherit" }}
            >
              Réessayer
            </Box>
          }
        >
          Impossible de charger l'encodage de cette mission.
        </Alert>
      ) : encoding ? (
        <>
          {(() => {
            const banner = encodingStateBanner(mission.status ?? "", mission.submittedAt, withoutInstrumentist);
            return banner ? <Alert severity={banner.severity} data-testid="surgeon-encoding-state">{banner.text}</Alert> : null;
          })()}

          {canEdit && !withoutInstrumentist && (
            <Alert severity="info" variant="outlined" data-testid="surgeon-encoding-editable">
              Vous pouvez compléter ou corriger les interventions et le matériel de votre mission. Les heures prestées
              restent celles déclarées par l'instrumentiste. Chaque modification est enregistrée à votre nom.
            </Alert>
          )}
          {withoutInstrumentist && (
            <Alert severity="info" variant="outlined" data-testid="surgeon-encoding-without-instrumentist">
              Aucune heure n'est déclarée pour cette activité et aucune prestation d'instrumentiste ne sera
              enregistrée : seules les prestations réellement applicables à la firme seront facturées.
            </Alert>
          )}
          {withoutInstrumentist && canEdit && (
            <Typography data-testid="surgeon-encoding-draft" sx={{ fontSize: 13, color: GRAY_500 }}>
              Brouillon : chaque intervention et chaque ligne de matériel est enregistrée dès que vous la validez.
              Vous pouvez quitter et reprendre plus tard ; rien n'est transmis au manager avant « Soumettre l'encodage ».
            </Typography>
          )}

          {/* L'essentiel en un coup d'œil, le détail ensuite (D-143). */}
          <Typography data-testid="surgeon-encoding-glance" sx={{ fontSize: 13, color: GRAY_500 }}>
            {(() => {
              const interventions = encoding.entries.length;
              const materials = encoding.entries.reduce((sum, e) => sum + e.materialLines.length, 0);
              return `${interventions} intervention${interventions > 1 ? "s" : ""} · ${materials} ligne${materials > 1 ? "s" : ""} de matériel`;
            })()}
          </Typography>

          {!withoutInstrumentist && (
            <EncodingHoursSummary execution={execution} plannedStartAt={mission.startAt} plannedEndAt={mission.endAt} />
          )}

          {canEdit ? (
            <InterventionsSection
              missionId={missionId}
              canEdit
              entries={encoding.entries ?? []}
              legacyInterventions={encoding.interventions ?? []}
              catalog={encoding.catalog}
              surgeonId={encoding.mission.surgeonId}
              canSubmit={canSubmit}
              onValidate={() => setSubmitOpen(true)}
              submitLabel="Soumettre l'encodage"
              progress={encoding.progress}
            />
          ) : encoding.entries.length === 0 ? (
            <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, px: 2, py: 3, textAlign: "center" }}>
              <Typography sx={{ fontSize: 14, fontWeight: 700, color: GRAY_900 }}>
                Aucun encodage disponible pour l'instant
              </Typography>
              <Typography sx={{ fontSize: 13, color: GRAY_500, mt: 0.5 }}>
                Les interventions et le matériel apparaîtront ici une fois encodés par l'instrumentiste.
              </Typography>
            </Box>
          ) : (
            <Stack spacing={1.5}>
              {encoding.entries.map((entry) => (
                <ReadOnlyInterventionCard key={`${entry.kind}:${entry.id}`} entry={entry} />
              ))}
            </Stack>
          )}

          <AnomalyReportSection missionId={missionId} />

          {canSubmit && (
            <SubmitDialog
              open={submitOpen}
              mission={mission}
              onClose={() => setSubmitOpen(false)}
              onSubmitted={() => {
                queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
                queryClient.invalidateQueries({ queryKey: ["missionEncoding", missionId] });
                queryClient.invalidateQueries({ queryKey: ["missions"] });
              }}
            />
          )}
        </>
      ) : null}
    </Stack>
  );
}
