import * as React from "react";
import { useNavigate } from "react-router-dom";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  Stack,
  Typography,
  TextField,
  MenuItem,
  Button,
  CircularProgress,
  Alert,
  Divider,
} from "@mui/material";

import { DateTimePicker } from "@mui/x-date-pickers/DateTimePicker";
import dayjs, { Dayjs } from "dayjs";

import type {
  MissionType,
  UserListItem,
  SiteListItem,
} from "../../features/missions/api/missions.types";
import {
  declareMission,
  fetchSites,
  fetchSurgeons,
} from "../../features/missions/api/missions.api";

import { useToast } from "../../ui/toast/useToast";

function buildSurgeonLabel(u: UserListItem): string {
  const display = (u.displayName ?? "").trim();
  if (display) return display;

  const firstname = (u.firstname ?? "").trim();
  const lastname = (u.lastname ?? "").trim();
  const full = `${firstname} ${lastname}`.trim();
  if (full) return full;

  return u.email;
}

// Symfony expected: Y-m-d\TH:i:sP (no ms, with offset)
function toBackendDateTime(d: Dayjs): string {
  return d.second(0).millisecond(0).format("YYYY-MM-DDTHH:mm:ssZ");
}

function formatDuration(minutes: number): string {
  if (!Number.isFinite(minutes) || minutes < 0) return "—";
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  const mm = String(m).padStart(2, "0");
  return `${h}h${mm}`;
}

export default function DeclareMissionPage() {
  const navigate = useNavigate();
  const toast = useToast();

  const defaultStart = React.useMemo(() => {
    const d = dayjs();
    const rounded = Math.ceil(d.minute() / 15) * 15;
    return d.minute(rounded).second(0).millisecond(0);
  }, []);

  const defaultEnd = React.useMemo(
    () => defaultStart.add(1, "hour"),
    [defaultStart],
  );

  const [siteId, setSiteId] = React.useState<number | "">("");
  const [surgeonUserId, setSurgeonUserId] = React.useState<number | "">("");
  const [type, setType] = React.useState<MissionType>("BLOCK");

  const [startAt, setStartAt] = React.useState<Dayjs | null>(defaultStart);
  const [endAt, setEndAt] = React.useState<Dayjs | null>(defaultEnd);

  const [startOpen, setStartOpen] = React.useState(false);
  const [endOpen, setEndOpen] = React.useState(false);

  const [comment, setComment] = React.useState<string>("");
  const [formError, setFormError] = React.useState<string>("");

  const { data: sites, isLoading: isLoadingSites } = useQuery({
    queryKey: ["sites", "list"],
    queryFn: () => fetchSites(),
  });

  const { data: surgeonsPage, isLoading: isLoadingSurgeons } = useQuery({
    queryKey: ["surgeons", "list"],
    queryFn: () => fetchSurgeons(1, 200),
  });

  const sitesOptions: SiteListItem[] = sites ?? [];
  const surgeons = surgeonsPage?.items ?? [];

  const hasDateOrderError = !!startAt && !!endAt && endAt.isBefore(startAt);

  // ✅ Règle: mission max 24h (endAt <= startAt + 24h)
  const exceeds24h =
    !!startAt && !!endAt && endAt.isAfter(startAt.add(24, "hour"));

  const durationMinutes =
    startAt && endAt ? endAt.diff(startAt, "minute") : NaN;

  const isBusy = isLoadingSites || isLoadingSurgeons;

  // Force le mode "mobile" même sur desktop (dialog au lieu du popover)
  const FORCE_MOBILE_MEDIA_QUERY = "@media (min-width: 999999px)";

  React.useEffect(() => {
    if (!startAt || !endAt) return;

    const minEnd = startAt;
    const maxEnd = startAt.add(24, "hour");

    if (endAt.isBefore(minEnd)) {
      setEndAt(minEnd);
      return;
    }
    if (endAt.isAfter(maxEnd)) {
      setEndAt(maxEnd);
    }
  }, [startAt, endAt]);

  const mutation = useMutation({
    mutationFn: async () => {
      setFormError("");

      if (siteId === "" || typeof siteId !== "number") {
        throw new Error("Veuillez sélectionner un site.");
      }
      if (surgeonUserId === "" || typeof surgeonUserId !== "number") {
        throw new Error("Veuillez sélectionner un chirurgien.");
      }
      if (!startAt || !endAt) {
        throw new Error("Veuillez renseigner l’horaire de la mission.");
      }
      if (endAt.isBefore(startAt)) {
        throw new Error("L’heure de fin doit être après l’heure de début.");
      }
      if (endAt.isAfter(startAt.add(24, "hour"))) {
        throw new Error(
          "Une mission ne peut pas dépasser 24 heures (ex : 23h → 6h OK).",
        );
      }

      return declareMission({
        siteId,
        surgeonUserId,
        type,
        startAt: toBackendDateTime(startAt),
        endAt: toBackendDateTime(endAt),
        comment: comment.trim(), // optionnel
      });
    },
    onSuccess: (mission) => {
      toast.success("Mission déclarée. En cours de validation.");
      navigate(`/app/i/missions/${mission.id}`, { replace: true });
    },
    onError: (err: any) => {
      // eslint-disable-next-line no-console
      console.error("DeclareMission error:", err);

      const msg =
        typeof err?.message === "string" && err.message
          ? err.message
          : "Une erreur est survenue lors de la déclaration. Veuillez réessayer.";

      setFormError(msg);
      toast.error(msg);
    },
  });

  const submitBusy = mutation.isPending;

  const handleStartChange = (v: Dayjs | null) => {
    setStartAt(v);
    if (!v) return;

    if (!endAt || endAt.isBefore(v)) {
      setEndAt(v.add(1, "hour"));
    }
  };

  const handleEndChange = (v: Dayjs | null) => {
    setEndAt(v);
  };

  const canSubmit =
    !isBusy &&
    !submitBusy &&
    siteId !== "" &&
    surgeonUserId !== "" &&
    !!startAt &&
    !!endAt &&
    !hasDateOrderError &&
    !exceeds24h;

  return (
    <Stack spacing={2}>
      <Typography variant="h6">Déclarer une mission</Typography>

      {formError && <Alert severity="error">{formError}</Alert>}

      {(isBusy || submitBusy) && <CircularProgress />}

      <TextField
        id="declare-site"
        name="declare-site"
        select
        label="Site"
        value={siteId}
        onChange={(e) => {
          const v = Number(e.target.value);
          setSiteId(Number.isFinite(v) ? v : "");
        }}
        fullWidth
        disabled={isBusy || submitBusy}
      >
        <MenuItem value="">Sélectionner…</MenuItem>
        {sitesOptions.map((s) => (
          <MenuItem key={s.id} value={s.id}>
            {s.name}
          </MenuItem>
        ))}
      </TextField>

      <TextField
        id="declare-surgeon"
        name="declare-surgeon"
        select
        label="Chirurgien"
        value={surgeonUserId}
        onChange={(e) => {
          const v = Number(e.target.value);
          setSurgeonUserId(Number.isFinite(v) ? v : "");
        }}
        fullWidth
        disabled={isBusy || submitBusy}
      >
        <MenuItem value="">Sélectionner…</MenuItem>
        {surgeons.map((u) => (
          <MenuItem key={u.id} value={u.id}>
            {buildSurgeonLabel(u)}
          </MenuItem>
        ))}
      </TextField>

      <TextField
        id="declare-type"
        name="declare-type"
        select
        label="Type"
        value={type}
        onChange={(e) => setType(e.target.value as MissionType)}
        fullWidth
        disabled={isBusy || submitBusy}
      >
        <MenuItem value="BLOCK">Bloc opératoire</MenuItem>
        <MenuItem value="CONSULTATION">Consultation</MenuItem>
      </TextField>

      <DateTimePicker
        label="Début"
        value={startAt}
        onChange={handleStartChange}
        open={startOpen}
        onOpen={() => setStartOpen(true)}
        onClose={() => setStartOpen(false)}
        disabled={isBusy || submitBusy}
        desktopModeMediaQuery={FORCE_MOBILE_MEDIA_QUERY}
        slotProps={{
          dialog: { fullScreen: true },
          textField: {
            id: "declare-startAt",
            name: "declare-startAt",
            fullWidth: true,
            onClick: () => setStartOpen(true),
            inputProps: { readOnly: true },
          },
        }}
      />

      <DateTimePicker
        label="Fin"
        value={endAt}
        onChange={handleEndChange}
        open={endOpen}
        onOpen={() => setEndOpen(true)}
        onClose={() => setEndOpen(false)}
        disabled={isBusy || submitBusy}
        desktopModeMediaQuery={FORCE_MOBILE_MEDIA_QUERY}
        slotProps={{
          dialog: { fullScreen: true },
          textField: {
            id: "declare-endAt",
            name: "declare-endAt",
            fullWidth: true,
            onClick: () => setEndOpen(true),
            inputProps: { readOnly: true },
            error: hasDateOrderError || exceeds24h,
            helperText: hasDateOrderError
              ? "La fin doit être après le début."
              : exceeds24h
                ? "Max 24h (ex : 23h → 6h OK)."
                : " ",
          },
        }}
      />

      <Typography variant="body2">
        Durée:{" "}
        {Number.isFinite(durationMinutes) && durationMinutes >= 0
          ? formatDuration(durationMinutes)
          : "—"}
      </Typography>

      <TextField
        id="declare-comment"
        name="declare-comment"
        label="Commentaire (optionnel)"
        value={comment}
        onChange={(e) => setComment(e.target.value)}
        fullWidth
        multiline
        minRows={3}
        disabled={isBusy || submitBusy}
      />

      <Divider />

      <Stack direction="row" spacing={1}>
        <Button
          variant="outlined"
          onClick={() => navigate(-1)}
          disabled={isBusy || submitBusy}
          fullWidth
        >
          Annuler
        </Button>

        <Button
          variant="contained"
          onClick={() => mutation.mutate()}
          disabled={!canSubmit}
          fullWidth
        >
          Déclarer
        </Button>
      </Stack>
    </Stack>
  );
}
