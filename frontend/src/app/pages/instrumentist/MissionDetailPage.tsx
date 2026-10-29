import * as React from "react";
import { useLocation, useNavigate, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Box,
  Button,
  Chip,
  CircularProgress,
  IconButton,
  Stack,
  Typography,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
} from "@mui/material";

import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import AccessTimeIcon from "@mui/icons-material/AccessTime";
import LocationOnIcon from "@mui/icons-material/LocationOn";
import PersonIcon from "@mui/icons-material/Person";
import EditNoteIcon from "@mui/icons-material/EditNote";
import MedicalServicesIcon from "@mui/icons-material/MedicalServices";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";

import dayjs from "dayjs";
import "dayjs/locale/fr";

import { declareExecutedWithoutInstrumentist, fetchMissionById, getMissionExecution } from "../../features/missions/api/missions.api";
import { useToast } from "../../ui/toast/useToast";
import type { Mission } from "../../features/missions/api/missions.types";
import { formatExecutionHours, HOURS_NOT_PROVIDED } from "../../features/missions/utils/missions.format";
import SubmitDialog from "../../features/missions/components/SubmitDialog";
import EditServiceHoursDialog from "../../features/missions/components/EditServiceHoursDialog";
import SurgeonAssignmentCard from "../../features/missions/components/SurgeonAssignmentCard";
import { MobileCard } from "../../ui/mobile/MobileCard";

dayjs.locale("fr");

type ChipColor = "default" | "info" | "primary" | "warning" | "error" | "success";

function getStatusChip(status: string): { label: string; color: ChipColor } {
  switch (status) {
    case "DRAFT": return { label: "Brouillon", color: "default" };
    case "OPEN": return { label: "Disponible", color: "info" };
    case "ASSIGNED": return { label: "En cours", color: "primary" };
    case "IN_PROGRESS": return { label: "En cours", color: "primary" };
    case "ENCODING_IN_PROGRESS": return { label: "Encodage en cours", color: "primary" };
    case "DECLARED": return { label: "À valider", color: "warning" };
    case "REJECTED": return { label: "Rejetée", color: "error" };
    case "SUBMITTED": return { label: "Soumise", color: "success" };
    case "VALIDATED": return { label: "Validée", color: "success" };
    case "CLOSED": return { label: "Clôturée", color: "default" };
    default: return { label: status || "—", color: "default" };
  }
}

function formatTime(iso?: string): string {
  if (!iso) return "—";
  return dayjs(iso).format("HH[h]mm");
}

function formatDate(iso?: string): string {
  if (!iso) return "—";
  const raw = dayjs(iso).format("dddd D MMMM YYYY");
  return raw.charAt(0).toUpperCase() + raw.slice(1);
}

function surgeonLabel(mission: Mission): string {
  const s = mission.surgeon;
  if (!s) return "—";
  const fn = (s.firstname ?? "").trim();
  const ln = (s.lastname ?? "").trim();
  const full = `${fn} ${ln}`.trim();
  if (full) return `Dr. ${full}`;
  return (s as any).displayName?.trim() || s.email || "—";
}

function missionTypeLabel(type?: string | null): string {
  if (type === "BLOCK") return "Bloc opératoire";
  if (type === "CONSULTATION") return "Consultation";
  return type ?? "—";
}

/**
 * Socle chirurgien (Lot 2, D-095) — le chirurgien doit voir qui est assigné à sa
 * mission (ou "À couvrir" si personne). Affiché aussi côté instrumentiste (lecture
 * neutre de sa propre affectation), aucune raison de le masquer par rôle.
 */
function instrumentistLabel(mission: Mission): string {
  const i = mission.instrumentist;
  if (!i) return "À couvrir";
  const fn = (i.firstname ?? "").trim();
  const ln = (i.lastname ?? "").trim();
  const full = `${fn} ${ln}`.trim();
  if (full) return full;
  return (i as any).displayName?.trim() || i.email || "—";
}

// ─── Reusable section card ──────────────────────────────────────────────────
type SectionCardProps = {
  icon: React.ReactNode;
  title: string;
  action?: React.ReactNode;
  children: React.ReactNode;
};

function SectionCard({ icon, title, action, children }: SectionCardProps) {
  return (
    <MobileCard>
      <Stack
        direction="row"
        alignItems="center"
        spacing={1}
        sx={{
          px: 2,
          py: 1.5,
          borderBottom: "1px solid",
          borderColor: "divider",
        }}
      >
        <Box sx={{ color: "primary.main", display: "flex" }}>{icon}</Box>
        <Typography variant="subtitle2" sx={{ flex: 1 }}>
          {title}
        </Typography>
        {action}
      </Stack>
      <Box sx={{ px: 2, py: 1.75 }}>{children}</Box>
    </MobileCard>
  );
}

// ─── Info row ───────────────────────────────────────────────────────────────
function InfoRow({ icon, label, value }: { icon: React.ReactNode; label?: string; value: string }) {
  return (
    <Stack direction="row" spacing={1.5} alignItems="flex-start">
      <Box sx={{ color: "text.disabled", display: "flex", mt: 0.2, flexShrink: 0 }}>{icon}</Box>
      <Box>
        {label && (
          <Typography variant="caption" color="text.disabled" display="block">
            {label}
          </Typography>
        )}
        <Typography variant="body2" fontWeight={500}>
          {value}
        </Typography>
      </Box>
    </Stack>
  );
}

// ─── Content ─────────────────────────────────────────────────────────────────
type Props = {
  missionId: number;
  embedded?: boolean;
  onCloseEmbedded?: () => void;
};

export function MissionDetailContent({ missionId, embedded = false, onCloseEmbedded }: Props) {
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();

  const [openSubmit, setOpenSubmit] = React.useState(false);
  const [openEditHours, setOpenEditHours] = React.useState(false);
  const [statusInfoOpen, setStatusInfoOpen] = React.useState(false);
  const [declareOpen, setDeclareOpen] = React.useState(false);
  const toast = useToast();

  const { data: mission, isLoading } = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: Number.isFinite(missionId) && missionId > 0,
  });

  // D-151 — l'instrumentiste dont l'affectation a pris fin ne garde qu'une vue minimale.
  const isEndedAssignment = mission?.myAssignmentStatus === "ENDED";

  // Même query key que MissionEncodingPage.tsx (lecture) et EditServiceHoursDialog.tsx
  // (mutation) — une seule source de vérité pour les heures prestées.
  const { data: execution } = useQuery({
    queryKey: ["mission-execution", missionId],
    queryFn: () => getMissionExecution(missionId),
    enabled: Number.isFinite(missionId) && missionId > 0 && !!mission && !isEndedAssignment,
  });

  // D-155 — déclarer l'activité réalisée sans instrumentiste (droit servi par le backend),
  // puis encoder depuis l'espace chirurgien.
  const declareMutation = useMutation({
    mutationFn: () => declareExecutedWithoutInstrumentist(missionId),
    onSuccess: () => {
      setDeclareOpen(false);
      toast.success("Activité déclarée : vous pouvez encoder les interventions et le matériel.");
      queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
      queryClient.invalidateQueries({ queryKey: ["missions"] });
      navigate(`/app/s/missions/${missionId}/encoding`);
    },
    onError: (err: any) => toast.error(err?.response?.data?.error?.message ?? err?.response?.data?.message ?? "Déclaration impossible."),
  });

  if (!Number.isFinite(missionId) || missionId <= 0)
    return <Typography>Identifiant invalide</Typography>;
  if (isLoading) return <CircularProgress />;
  if (!mission) return <Typography>Mission introuvable</Typography>;

  const allowed = mission.allowedActions ?? [];
  const canDeclareWithoutInstrumentist = allowed.includes("declare_executed_without_instrumentist");
  const withoutInstrumentist = mission.executedWithoutInstrumentist ?? null;
  const canEncoding = allowed.includes("encoding") || allowed.includes("edit_encoding");
  const canSubmit = allowed.includes("submit");
  // D-172 — encodage déjà soumis (statut backend) : il attend la validation du manager ; une
  // correction reste possible tant que le backend la sert, une nouvelle soumission n'a pas de sens.
  const encodingSubmitted = mission.status === "SUBMITTED";
  const canEditHours = allowed.includes("edit_hours");
  // Lot 6 (D-100) — lecture seule chirurgien : jamais en même temps que canEncoding
  // (allowedActions ne renvoie jamais les deux pour un même viewer), mais vérifié quand
  // même pour ne jamais faire disparaître le CTA "Gérer" de l'instrumentiste.
  const canViewEncoding = !canEncoding && allowed.includes("view_encoding");
  // D-146 bis — même composant pour l'instrumentiste (/app/i, consultation de sa mission
  // validée) et le chirurgien (/app/s) : la consultation reste dans l'espace de l'utilisateur.
  const viewEncodingPath = location.pathname.startsWith("/app/i/")
    ? `/app/i/missions/${mission.id}/encoding`
    : `/app/s/missions/${mission.id}/encoding`;
  const isInstrumentistView = location.pathname.startsWith("/app/i/");
  // D-154 — chaque rôle encode dans son propre espace : l'instrumentiste dans /app/i, le
  // chirurgien dans /app/s (jamais renvoyé vers l'écran instrumentiste).
  const encodingPath = isInstrumentistView
    ? `/app/i/missions/${mission.id}/encoding`
    : `/app/s/missions/${mission.id}/encoding`;

  // D-151 — « Affectation annulée », jamais le statut courant de la mission ni pourquoi.
  const { label: chipLabel, color: chipColor } = isEndedAssignment
    ? { label: "Affectation annulée", color: "default" as ChipColor }
    : getStatusChip(String(mission.status ?? ""));
  const hoursLabel = formatExecutionHours(execution);
  const isEncodingPending =
    (mission.status === "ASSIGNED" || mission.status === "IN_PROGRESS" || mission.status === "DECLARED") &&
    canEncoding;

  const hasStatusInfo = mission.status === "DECLARED" || mission.status === "REJECTED";

  return (
    <Stack spacing={2}>
      {/* Header */}
      {!embedded && (
        <Stack direction="row" alignItems="center" spacing={1}>
          <IconButton size="small" onClick={() => navigate(-1)}>
            <ArrowBackIcon fontSize="small" />
          </IconButton>
          <Typography variant="subtitle1" sx={{ flex: 1 }}>
            Mission #{mission.id}
          </Typography>
          <Chip label={chipLabel} color={chipColor} size="small" />
          {hasStatusInfo && (
            <IconButton size="small" onClick={() => setStatusInfoOpen(true)}>
              <HelpOutlineIcon fontSize="small" />
            </IconButton>
          )}
        </Stack>
      )}

      {/* Zone 1 — Info mission */}
      <SectionCard icon={<LocationOnIcon fontSize="small" />} title="Mission">
        <Stack spacing={1.5}>
          <InfoRow icon={<LocationOnIcon fontSize="small" />} label="Site" value={mission.site?.name ?? "—"} />
          <InfoRow
            icon={<AccessTimeIcon fontSize="small" />}
            label="Horaire"
            value={`${formatDate(mission.startAt)} · ${formatTime(mission.startAt)} → ${formatTime(mission.endAt)}`}
          />
          <InfoRow icon={<PersonIcon fontSize="small" />} label="Chirurgien" value={surgeonLabel(mission)} />
          {!isEndedAssignment && (
            <InfoRow
              icon={<PersonIcon fontSize="small" />}
              label="Instrumentiste"
              value={withoutInstrumentist
                ? `Sans instrumentiste — activité déclarée${withoutInstrumentist.declaredByName ? ` par ${withoutInstrumentist.declaredByName}` : ""}`
                : instrumentistLabel(mission)}
            />
          )}
          <InfoRow
            icon={<MedicalServicesIcon fontSize="small" />}
            label="Type"
            value={missionTypeLabel((mission as any).type)}
          />
        </Stack>
      </SectionCard>

      {isEndedAssignment && (
        <Typography variant="body2" color="text.secondary" data-testid="assignment-ended-note">
          Votre affectation à cette mission n'a pas pu être maintenue. Aucune action n'est nécessaire de votre part.
        </Typography>
      )}

      {/* D-151 — chirurgien de la mission : affectation en cours, délai et refus éventuel. */}
      {!isInstrumentistView && mission.currentAssignment && <SurgeonAssignmentCard mission={mission} />}

      {!isEndedAssignment && (
      <>
      {/* Zone 2 — Prestation */}
      <SectionCard
        icon={<AccessTimeIcon fontSize="small" />}
        title="Prestation"
        action={
          canEditHours ? (
            <Button size="small" variant="text" onClick={() => setOpenEditHours(true)}>
              Modifier
            </Button>
          ) : undefined
        }
      >
        <Stack spacing={1.5}>
          <Stack direction="row" justifyContent="space-between" alignItems="center">
            <Typography variant="body2" color="text.secondary">
              Heures prestées
            </Typography>
            <Typography variant="body2" fontWeight={700} color={withoutInstrumentist || hoursLabel === HOURS_NOT_PROVIDED ? "text.disabled" : "text.primary"}>
              {withoutInstrumentist ? "Aucune — activité sans instrumentiste" : hoursLabel}
            </Typography>
          </Stack>

          {isEncodingPending && !withoutInstrumentist && (
            <Box
              sx={{
                bgcolor: "#EFF6FF",
                borderRadius: 2,
                px: 1.5,
                py: 1,
                border: "1px solid #DBEAFE",
              }}
            >
              <Typography variant="caption" color="primary.dark">
                Pour que les heures soient comptabilisées, l'encodage de la mission doit être terminé.
              </Typography>
            </Box>
          )}
        </Stack>
      </SectionCard>

      {/* Zone 3 — Interventions / Matériel */}
      <SectionCard
        icon={<EditNoteIcon fontSize="small" />}
        title="Interventions & matériel"
        action={
          canEncoding ? (
            <Button
              size="small"
              variant="text"
              onClick={() => navigate(encodingPath)}
            >
              Gérer
            </Button>
          ) : canViewEncoding ? (
            <Button
              size="small"
              variant="text"
              onClick={() => navigate(viewEncodingPath)}
            >
              Voir
            </Button>
          ) : undefined
        }
      >
        <Typography variant="body2" color="text.secondary">
          {canEncoding && encodingSubmitted
            ? "Encodage soumis — en attente de validation par le manager. Vous pouvez encore le corriger tant qu'il n'est pas validé."
            : canEncoding
            ? "Encodez les interventions et le matériel utilisé."
            : canViewEncoding
            ? isInstrumentistView
              ? "Consultez vos heures prestées, interventions et matériel encodés (lecture seule)."
              : "Consultez les interventions et le matériel encodés par l'instrumentiste."
            : "Encodage non disponible pour cette mission."}
        </Typography>
      </SectionCard>

      {/* D-155 — mission commencée sans instrumentiste : l'activité a-t-elle eu lieu ? */}
      {canDeclareWithoutInstrumentist && (
        <SectionCard icon={<EditNoteIcon fontSize="small" />} title="Activité réalisée sans instrumentiste">
          <Stack spacing={1.5}>
            <Typography variant="body2" color="text.secondary">
              Aucune instrumentiste n'a couvert cette mission. Si l'intervention a bien eu lieu, encodez vous-même
              les interventions et le matériel utilisés, puis soumettez l'encodage au manager.
            </Typography>
            <Button variant="contained" disableElevation onClick={() => setDeclareOpen(true)} data-testid="declare-without-instrumentist">
              Encoder les interventions et le matériel
            </Button>
          </Stack>
        </SectionCard>
      )}

      {/* Actions */}
      {(canEncoding || canSubmit || canViewEncoding) && (
        <Stack spacing={1.5}>
          {canEncoding && (
            <Button
              variant="contained"
              disableElevation
              fullWidth
              size="large"
              onClick={() => navigate(encodingPath)}
              sx={{ borderRadius: 2, fontWeight: 700 }}
            >
              {encodingSubmitted ? "Modifier l'encodage" : "Encoder la mission"}
            </Button>
          )}
          {canViewEncoding && (
            <Button
              variant="outlined"
              disableElevation
              fullWidth
              size="large"
              onClick={() => navigate(viewEncodingPath)}
              sx={{ borderRadius: 2, fontWeight: 700 }}
            >
              Encodage de l'intervention
            </Button>
          )}
          {canSubmit && !encodingSubmitted && (
            <Button
              variant={canEncoding ? "outlined" : "contained"}
              disableElevation
              fullWidth
              size="large"
              onClick={() => setOpenSubmit(true)}
              sx={{ borderRadius: 2, fontWeight: 700 }}
            >
              Terminer l'encodage
            </Button>
          )}
        </Stack>
      )}
      </>
      )}

      {/* Dialogs */}
      <SubmitDialog
        open={openSubmit}
        mission={mission}
        onClose={() => setOpenSubmit(false)}
        onSubmitted={() => {
          queryClient.invalidateQueries({ queryKey: ["mission", mission.id] });
          queryClient.invalidateQueries({ queryKey: ["missions"] });
          if (embedded) onCloseEmbedded?.();
        }}
        onCompleteHours={canEditHours ? () => { setOpenSubmit(false); setOpenEditHours(true); } : undefined}
      />

      {canEditHours && openEditHours && (
        <EditServiceHoursDialog
          open={openEditHours}
          onClose={() => setOpenEditHours(false)}
          mission={mission}
        />
      )}

      <Dialog open={declareOpen} onClose={() => !declareMutation.isPending && setDeclareOpen(false)}>
        <DialogTitle>Déclarer l'activité réalisée sans instrumentiste</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" sx={{ mb: 1 }}>
            La mission ne sera plus proposée aux instrumentistes. Vous encoderez les interventions et le matériel
            réellement utilisés, puis soumettrez l'encodage au manager pour contrôle.
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Aucune heure ni aucune prestation d'instrumentiste ne sera enregistrée ; seules les prestations
            réellement applicables à la firme seront facturées.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeclareOpen(false)} disabled={declareMutation.isPending}>Annuler</Button>
          <Button variant="contained" disableElevation onClick={() => declareMutation.mutate()} disabled={declareMutation.isPending} data-testid="confirm-declare-without-instrumentist">
            {declareMutation.isPending ? <CircularProgress size={18} /> : "Confirmer la déclaration"}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={statusInfoOpen} onClose={() => setStatusInfoOpen(false)}>
        <DialogTitle>
          {mission.status === "DECLARED" ? "Mission déclarée" : "Mission rejetée"}
        </DialogTitle>
        <DialogContent dividers>
          {mission.status === "DECLARED" && (
            <Typography>
              Cette mission est en attente de validation par le manager. Certaines actions peuvent être indisponibles.
            </Typography>
          )}
          {mission.status === "REJECTED" && (
            <Typography>
              Cette mission a été rejetée par le manager. L'encodage a été supprimé.
            </Typography>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setStatusInfoOpen(false)}>OK</Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}

export default function MissionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const missionId = Number(id);
  if (!Number.isFinite(missionId) || missionId <= 0)
    return <Typography>Identifiant invalide</Typography>;
  return <MissionDetailContent missionId={missionId} />;
}
