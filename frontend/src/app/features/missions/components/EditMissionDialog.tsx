import * as React from "react";
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  FormHelperText,
  IconButton,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
  Tooltip,
} from "@mui/material";
import InfoOutlinedIcon from "@mui/icons-material/InfoOutlined";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { DateTimePicker } from "@mui/x-date-pickers/DateTimePicker";
import dayjs, { Dayjs } from "dayjs";

import type {
  Mission,
  MissionType,
  SchedulePrecision,
} from "../api/missions.types";
import { patchMission } from "../api/missions.api";
import {
  getMissionSiteId,
  type MissionPatchBody,
} from "../api/missions.requests";

type Props = {
  open: boolean;
  onClose: () => void;
  mission: Mission;
};

function extractApiError(err: unknown): { status?: number; message: string } {
  const status = (err as any)?.response?.status as number | undefined;
  const data = (err as any)?.response?.data;

  const message =
    (typeof data === "string" && data) ||
    data?.message ||
    data?.detail ||
    "Une erreur est survenue";

  return { status, message };
}

function parseMissionIsoToDayjs(value?: string | null): Dayjs | null {
  if (!value) return null;
  const d = dayjs(value);
  if (!d.isValid()) return null;
  return d.tz("Europe/Brussels");
}

function toBrusselsIso(dt: Dayjs): string {
  return dt.tz("Europe/Brussels").format("YYYY-MM-DDTHH:mm:ssZ");
}

function typeLabel(t: MissionType): string {
  if (t === "BLOCK") return "Bloc opératoire";
  if (t === "CONSULTATION") return "Consultation";
  return t;
}

export default function EditMissionDialog({ open, onClose, mission }: Props) {
  const queryClient = useQueryClient();

  /**
   * LOT 2a (verrouillé) :
   * - Le site N’EST PAS éditable ici.
   * - On affiche le nom du site, et on renvoie siteId inchangé au PATCH.
   */
  const siteName = mission.site?.name ?? "—";
  const siteIdFromMission =
    typeof mission.site?.id === "number"
      ? mission.site.id
      : getMissionSiteId(mission);

  const [startAt, setStartAt] = React.useState<Dayjs | null>(
    parseMissionIsoToDayjs(mission.startAt)
  );
  const [endAt, setEndAt] = React.useState<Dayjs | null>(
    parseMissionIsoToDayjs(mission.endAt)
  );
  const [schedulePrecision, setSchedulePrecision] =
    React.useState<SchedulePrecision>(mission.schedulePrecision);
  const [type, setType] = React.useState<MissionType>(mission.type);

  const [formError, setFormError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!open) return;

    setStartAt(parseMissionIsoToDayjs(mission.startAt));
    setEndAt(parseMissionIsoToDayjs(mission.endAt));
    setSchedulePrecision(mission.schedulePrecision);
    setType(mission.type);
    setFormError(null);
  }, [open, mission]);

  const mutation = useMutation({
    mutationFn: async (body: MissionPatchBody) =>
      patchMission(mission.id, body),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ["mission", mission.id],
      });
      onClose();
    },
    onError: (err) => {
      const { status, message } = extractApiError(err);
      if (status === 401)
        return setFormError("Session expirée. Veuillez vous reconnecter.");
      if (status === 403) return setFormError("Accès interdit.");
      if (status === 404) return setFormError("Mission introuvable.");
      if (status === 409)
        return setFormError("Conflit. Recharge puis réessaye.");
      if (status === 422)
        return setFormError(
          message || "Données invalides. Corrige le formulaire."
        );
      setFormError(message || "Erreur serveur.");
    },
  });

  function handleSubmit() {
    setFormError(null);

    if (!startAt || !startAt.isValid()) {
      setFormError("Veuillez renseigner une date/heure de début valide.");
      return;
    }
    if (!endAt || !endAt.isValid()) {
      setFormError("Veuillez renseigner une date/heure de fin valide.");
      return;
    }
    if (endAt.isBefore(startAt)) {
      setFormError("La fin doit être après le début.");
      return;
    }

    const parsedSiteId = Number(siteIdFromMission);
    if (!Number.isFinite(parsedSiteId) || parsedSiteId <= 0) {
      setFormError("Site invalide (siteId manquant dans la mission).");
      return;
    }

    mutation.mutate({
      startAt: toBrusselsIso(startAt),
      endAt: toBrusselsIso(endAt),
      schedulePrecision,
      type,
      siteId: parsedSiteId,
    });
  }

  return (
    <Dialog
      open={open}
      onClose={mutation.isPending ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Éditer la mission #{mission.id}</DialogTitle>

      <DialogContent>
        <Stack spacing={2} mt={1}>
          {formError ? <Alert severity="error">{formError}</Alert> : null}

          <TextField
            label="Site"
            value={siteName}
            fullWidth
            disabled
            helperText="Lecture seule (Lot 2a). Le changement de site fera l’objet d’un lot distinct si nécessaire."
          />

          <DateTimePicker
            label="Début"
            value={startAt}
            onChange={(v) => setStartAt(v)}
            disabled={mutation.isPending}
            slotProps={{ textField: { fullWidth: true, required: true } }}
          />

          <DateTimePicker
            label="Fin"
            value={endAt}
            onChange={(v) => setEndAt(v)}
            disabled={mutation.isPending}
            slotProps={{ textField: { fullWidth: true, required: true } }}
          />

          <FormControl fullWidth required disabled={mutation.isPending}>
            <Stack
              direction="row"
              alignItems="center"
              justifyContent="space-between"
            >
              <InputLabel id="schedulePrecision-label">Précision</InputLabel>

              <Tooltip
                title={
                  <>
                    <div>
                      <strong>Horaire confirmé</strong> : créneau fixe (planning
                      validé).
                    </div>
                    <div>
                      <strong>Horaire estimé</strong> : peut encore bouger
                      (organisation en cours).
                    </div>
                  </>
                }
              >
                <IconButton size="small" sx={{ mt: 0.5 }}>
                  <InfoOutlinedIcon fontSize="small" />
                </IconButton>
              </Tooltip>
            </Stack>

            <Select
              labelId="schedulePrecision-label"
              label="Précision"
              value={schedulePrecision}
              onChange={(e) =>
                setSchedulePrecision(e.target.value as SchedulePrecision)
              }
            >
              <MenuItem value="EXACT">Horaire confirmé</MenuItem>
              <MenuItem value="APPROXIMATE">Horaire estimé</MenuItem>
            </Select>

            <FormHelperText>
              Utilise “Confirmé” quand les horaires sont définitifs.
            </FormHelperText>
          </FormControl>

          <FormControl fullWidth required disabled={mutation.isPending}>
            <InputLabel id="type-label">Type</InputLabel>
            <Select
              labelId="type-label"
              label="Type"
              value={type}
              onChange={(e) => setType(e.target.value as MissionType)}
            >
              <MenuItem value="BLOCK">{typeLabel("BLOCK")}</MenuItem>
              <MenuItem value="CONSULTATION">
                {typeLabel("CONSULTATION")}
              </MenuItem>
            </Select>

            <FormHelperText>
              “Bloc opératoire” = mission au bloc. “Consultation” = activité
              clinique hors bloc.
            </FormHelperText>
          </FormControl>
        </Stack>
      </DialogContent>

      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={mutation.isPending}>
          Annuler
        </Button>
        <Button
          variant="contained"
          onClick={handleSubmit}
          disabled={mutation.isPending}
        >
          Enregistrer
        </Button>
      </DialogActions>
    </Dialog>
  );
}
