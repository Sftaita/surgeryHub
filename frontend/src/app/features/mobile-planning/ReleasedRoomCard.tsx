import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  Typography,
} from "@mui/material";
import MeetingRoomOutlinedIcon from "@mui/icons-material/MeetingRoomOutlined";
import CheckCircleOutlineIcon from "@mui/icons-material/CheckCircleOutline";

import { releaseReleasedRoom, takeOverReleasedRoom } from "../planning-v2/api/planningV2.api";
import type { ReleasedRoomSlotV2 } from "../planning-v2/api/planningV2.types";
import { DateTile } from "../../ui/mobile/DateTile";
import { StatusPill } from "../../ui/mobile/StatusPill";
import { useToast } from "../../ui/toast/useToast";
import { AVAILABLE_ROOM_PERIOD_LABELS, parseYmdToLocalDate } from "./planningPrimitives";

const BLUE_700 = "#1B5FD0";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

type ApiError = { code?: string; message?: string; takenBy?: { id: number; name: string } | null };

function apiErrorOf(err: unknown): ApiError {
  const data = (err as { response?: { data?: { error?: ApiError } } })?.response?.data;
  return data?.error ?? {};
}

/** « du Dr X » plutôt que « de Dr X » — le nom arrive déjà préfixé « Dr » du serveur. */
function absenceOf(name: string | null | undefined): string {
  if (!name) return "d'un confrère";
  return name.startsWith("Dr ") ? `du ${name}` : `de ${name}`;
}

function formatLongDate(ymd: string): string {
  const label = parseYmdToLocalDate(ymd.slice(0, 10)).toLocaleDateString("fr-BE", { weekday: "long", day: "numeric", month: "long" });
  return label.charAt(0).toUpperCase() + label.slice(1);
}

function timeLabelOf(slot: ReleasedRoomSlotV2): string {
  return slot.startTime && slot.endTime ? `${slot.startTime}–${slot.endTime}` : (AVAILABLE_ROOM_PERIOD_LABELS[slot.period] ?? slot.period);
}

/**
 * D-124 — « Reprendre une salle libérée », côté chirurgien. Le composant n'infère RIEN : les
 * CTA n'apparaissent que si `slot.allowedActions` (calculé par le backend : Voter + état) les
 * autorise, l'état affiché (« Salle reprise », Mission « À pourvoir » / instrumentiste) vient
 * tel quel du serveur, et chaque action est UN seul appel métier — la réservation de la salle
 * et la création de la Mission sont atomiques côté serveur. Après toute réponse (succès ou
 * 409), l'état serveur est rechargé : jamais un masquage local qui pourrait mentir.
 */
export function ReleasedRoomCard({ slot }: { slot: ReleasedRoomSlotV2 }) {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [confirm, setConfirm] = React.useState<"takeOver" | "release" | null>(null);

  const refreshServerState = React.useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: ["available-rooms"] });
    void queryClient.invalidateQueries({ queryKey: ["missions"] });
  }, [queryClient]);

  const takeOver = useMutation({
    mutationFn: () => takeOverReleasedRoom(slot.id),
    onSuccess: () => {
      toast.success("Salle reprise — une mission est ouverte aux instrumentistes du site.");
    },
    onError: (err) => {
      const e = apiErrorOf(err);
      if (e.code === "ROOM_SLOT_ALREADY_TAKEN") {
        toast.warning(e.message ?? "Cette salle vient d'être reprise par un confrère.");
      } else {
        toast.error(e.message ?? "Impossible de reprendre cette salle.");
      }
    },
    onSettled: () => {
      setConfirm(null);
      refreshServerState();
    },
  });

  const release = useMutation({
    mutationFn: () => releaseReleasedRoom(slot.id),
    onSuccess: () => {
      toast.success("Salle libérée — elle est de nouveau disponible pour vos confrères.");
    },
    onError: (err) => {
      toast.error(apiErrorOf(err).message ?? "Impossible de libérer cette salle.");
    },
    onSettled: () => {
      setConfirm(null);
      refreshServerState();
    },
  });

  const start = parseYmdToLocalDate(slot.occurrenceDate.slice(0, 10));
  const isClaimed = slot.status === "CLAIMED";
  const mission = slot.takeoverMission;
  const busy = takeOver.isPending || release.isPending;

  return (
    <Box
      data-testid={`available-room-row-${slot.id}`}
      sx={{
        background: "#fff", border: "1px solid #E7EBEF", borderRadius: "16px", padding: "14px 16px",
        boxShadow: SHADOW_XS, display: "flex", flexDirection: "column", gap: "12px",
      }}
    >
      <Box sx={{ display: "flex", alignItems: "center", gap: "14px" }}>
        <DateTile
          day={String(start.getDate()).padStart(2, "0")}
          month={start.toLocaleDateString("fr-BE", { month: "short" }).replace(".", "").toUpperCase()}
          variant="aVenir"
          preset="list"
        />
        <MeetingRoomOutlinedIcon sx={{ color: BLUE_700, fontSize: 20 }} />
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Typography sx={{ fontSize: 15, fontWeight: 700 }} noWrap>
            {slot.site?.name ?? "—"} — {timeLabelOf(slot)}
          </Typography>
          <Typography sx={{ mt: "3px", fontSize: 13, color: "text.secondary" }} noWrap>
            {slot.surgeon?.name ?? "—"} absent
            {isClaimed && !slot.claimedByMe && slot.claimedBy ? ` → Salle reprise par ${slot.claimedBy.name}` : ""}
          </Typography>
        </Box>
        {isClaimed
          ? <StatusPill variant="confirmee" label="Salle reprise" />
          : <StatusPill variant="aVenir" label="Disponible" />}
      </Box>

      {isClaimed && slot.claimedByMe && (
        <Stack spacing={0.75} data-testid={`released-room-mine-${slot.id}`}>
          <Stack direction="row" spacing={0.75} alignItems="center">
            <CheckCircleOutlineIcon sx={{ fontSize: 18, color: "#2C7D5F" }} />
            <Typography sx={{ fontSize: 13.5, fontWeight: 600 }}>Vous avez repris cette salle</Typography>
          </Stack>
          {mission && (
            <Stack direction="row" spacing={1} alignItems="center">
              <Typography sx={{ fontSize: 13, color: "text.secondary" }}>Mission instrumentiste :</Typography>
              {mission.instrumentist
                ? <StatusPill variant="confirmee" label={mission.instrumentist.name} />
                : mission.status === "OPEN"
                  ? <StatusPill variant="enAttente" label="À pourvoir" />
                  : <StatusPill variant="refusee" label="Annulée" />}
            </Stack>
          )}
        </Stack>
      )}

      {(slot.allowedActions.takeOver || slot.allowedActions.release) && (
        <Stack direction="row" justifyContent="flex-end" spacing={1}>
          {slot.allowedActions.release && (
            <Button size="small" variant="outlined" color="inherit" disabled={busy} onClick={() => setConfirm("release")}>
              Libérer la salle
            </Button>
          )}
          {slot.allowedActions.takeOver && (
            <Button size="small" variant="contained" disabled={busy} onClick={() => setConfirm("takeOver")}>
              Reprendre cette salle
            </Button>
          )}
        </Stack>
      )}

      <Dialog open={confirm === "takeOver"} onClose={() => !busy && setConfirm(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Reprendre cette salle ?</DialogTitle>
        <DialogContent>
          <Stack spacing={0.5} sx={{ mb: 2 }}>
            <Typography fontWeight={700}>{slot.site?.name ?? "—"}</Typography>
            <Typography>{formatLongDate(slot.occurrenceDate)}</Typography>
            <Typography>{timeLabelOf(slot)}</Typography>
            <Typography sx={{ color: "text.secondary", fontSize: 14 }}>
              Salle libérée suite à l'absence {absenceOf(slot.surgeon?.name)}
            </Typography>
          </Stack>
          <Typography sx={{ fontSize: 14 }}>
            En confirmant, cette salle vous sera réservée et une mission sera ouverte aux instrumentistes du site.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirm(null)} disabled={busy}>Annuler</Button>
          <Button variant="contained" onClick={() => takeOver.mutate()} disabled={busy}>Reprendre la salle</Button>
        </DialogActions>
      </Dialog>

      <Dialog open={confirm === "release"} onClose={() => !busy && setConfirm(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Libérer la salle ?</DialogTitle>
        <DialogContent>
          <Typography sx={{ fontSize: 14 }}>
            {slot.site?.name ?? "—"} · {formatLongDate(slot.occurrenceDate)} · {timeLabelOf(slot)}
          </Typography>
          <Typography sx={{ fontSize: 14, mt: 1.5 }}>
            La mission instrumentiste associée sera annulée
            {mission?.instrumentist ? ` et ${mission.instrumentist.name} en sera informée` : ""}. La salle redeviendra
            disponible pour vos confrères.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirm(null)} disabled={busy}>Annuler</Button>
          <Button variant="contained" color="error" onClick={() => release.mutate()} disabled={busy}>Libérer la salle</Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
}
