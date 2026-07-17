import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
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

import { fetchMissionById } from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import SubmitDialog from "../../features/missions/components/SubmitDialog";
import EditServiceHoursDialog from "../../features/missions/components/EditServiceHoursDialog";
import { MobileCard } from "../../ui/mobile/MobileCard";

dayjs.locale("fr");

type ChipColor = "default" | "info" | "primary" | "warning" | "error" | "success";

function getStatusChip(status: string): { label: string; color: ChipColor } {
  switch (status) {
    case "DRAFT": return { label: "Brouillon", color: "default" };
    case "OPEN": return { label: "Disponible", color: "info" };
    case "ASSIGNED": return { label: "En cours", color: "primary" };
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

function formatHours(hours?: string | number | null): string {
  if (hours === null || hours === undefined || hours === "") return "—";
  const n = typeof hours === "string" ? Number(hours) : hours;
  if (!Number.isFinite(n)) return "—";
  return `${n} h`;
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
  const queryClient = useQueryClient();

  const [openSubmit, setOpenSubmit] = React.useState(false);
  const [openEditHours, setOpenEditHours] = React.useState(false);
  const [statusInfoOpen, setStatusInfoOpen] = React.useState(false);

  const { data: mission, isLoading } = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: Number.isFinite(missionId) && missionId > 0,
  });

  if (!Number.isFinite(missionId) || missionId <= 0)
    return <Typography>Identifiant invalide</Typography>;
  if (isLoading) return <CircularProgress />;
  if (!mission) return <Typography>Mission introuvable</Typography>;

  const allowed = mission.allowedActions ?? [];
  const canEncoding = allowed.includes("encoding") || allowed.includes("edit_encoding");
  const canSubmit = allowed.includes("submit");
  const canEditHours = allowed.includes("edit_hours");

  const { label: chipLabel, color: chipColor } = getStatusChip(String(mission.status ?? ""));
  const hoursLabel = formatHours(mission.service?.hours ?? null);
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
          <InfoRow
            icon={<MedicalServicesIcon fontSize="small" />}
            label="Type"
            value={missionTypeLabel((mission as any).type)}
          />
        </Stack>
      </SectionCard>

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
            <Typography variant="body2" fontWeight={700} color={hoursLabel === "—" ? "text.disabled" : "text.primary"}>
              {hoursLabel}
            </Typography>
          </Stack>

          {isEncodingPending && (
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
              onClick={() => navigate(`/app/i/missions/${mission.id}/encoding`)}
            >
              Gérer
            </Button>
          ) : undefined
        }
      >
        <Typography variant="body2" color="text.secondary">
          {canEncoding
            ? "Encodez les interventions et le matériel utilisé."
            : "Encodage non disponible pour cette mission."}
        </Typography>
      </SectionCard>

      {/* Actions */}
      {(canEncoding || canSubmit) && (
        <Stack spacing={1.5}>
          {canEncoding && (
            <Button
              variant="contained"
              disableElevation
              fullWidth
              size="large"
              onClick={() => navigate(`/app/i/missions/${mission.id}/encoding`)}
              sx={{ borderRadius: 2, fontWeight: 700 }}
            >
              Encoder la mission
            </Button>
          )}
          {canSubmit && (
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
      />

      {canEditHours && openEditHours && (
        <EditServiceHoursDialog
          open={openEditHours}
          onClose={() => setOpenEditHours(false)}
          mission={mission}
        />
      )}

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
