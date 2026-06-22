import * as React from "react";
import {
  Box, Button, CircularProgress, Drawer, IconButton, MenuItem, Select, Stack,
  TextField, Typography,
} from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import DeleteOutlineIcon from "@mui/icons-material/DeleteOutline";
import AddIcon from "@mui/icons-material/Add";
import CheckCircleOutlinedIcon from "@mui/icons-material/CheckCircleOutlined";

import type { SurgeonSchedulePostV2, OccurrenceExceptionType } from "../api/planningV2.types";
import {
  getPostExceptions, createPostException, deleteException, extractErrorV2,
} from "../api/planningV2.api";
import { useToast } from "../../../ui/toast/useToast";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { SearchableSelect, type SearchableOption } from "./SearchableSelect";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

const TYPE_LABELS: Record<OccurrenceExceptionType, string> = {
  CANCELLED: "Annulé",
  MOVED: "Déplacé",
  TIME_OVERRIDE: "Horaire modifié",
  INSTRUMENTIST_OVERRIDE: "Instrumentiste remplacée",
};

const TYPE_TOKENS: Record<OccurrenceExceptionType, { fg: string; bg: string }> = {
  CANCELLED: { fg: planningV2Colors.critFg, bg: planningV2Colors.critBg },
  MOVED: { fg: planningV2Colors.infoFg, bg: planningV2Colors.infoBg },
  TIME_OVERRIDE: { fg: planningV2Colors.infoFg, bg: planningV2Colors.infoBg },
  INSTRUMENTIST_OVERRIDE: { fg: planningV2Colors.warnFg, bg: planningV2Colors.warnBg },
};

const MONTHS_SHORT = ["JANV", "FÉVR", "MARS", "AVR", "MAI", "JUIN", "JUIL", "AOÛT", "SEPT", "OCT", "NOV", "DÉC"];

interface Props {
  open: boolean;
  onClose: () => void;
  post: SurgeonSchedulePostV2 | null;
  instrumentists: SearchableOption[];
}

export function ExceptionsSheet({ open, onClose, post, instrumentists }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const [adding, setAdding] = React.useState(false);

  const [type, setType] = React.useState<OccurrenceExceptionType>("CANCELLED");
  const [occurrenceDate, setOccurrenceDate] = React.useState("");
  const [overrideDate, setOverrideDate] = React.useState("");
  const [overrideStartTime, setOverrideStartTime] = React.useState("");
  const [overrideEndTime, setOverrideEndTime] = React.useState("");
  const [overrideInstrumentistId, setOverrideInstrumentistId] = React.useState<number | null>(null);

  React.useEffect(() => {
    if (open) {
      setAdding(false);
      setType("CANCELLED");
      setOccurrenceDate("");
      setOverrideDate("");
      setOverrideStartTime("");
      setOverrideEndTime("");
      setOverrideInstrumentistId(null);
    }
  }, [open, post?.id]);

  const exceptionsQuery = useQuery({
    queryKey: ["planning-v2", "post-exceptions", post?.id],
    queryFn: () => getPostExceptions(post!.id),
    enabled: open && !!post,
  });

  function invalidate() {
    qc.invalidateQueries({ queryKey: ["planning-v2", "post-exceptions", post?.id] });
  }

  const createMutation = useMutation({
    mutationFn: () =>
      createPostException(post!.id, {
        type,
        occurrenceDate,
        overrideDate: type === "MOVED" ? overrideDate : undefined,
        overrideStartTime: type === "TIME_OVERRIDE" || type === "MOVED" ? (overrideStartTime || undefined) : undefined,
        overrideEndTime: type === "TIME_OVERRIDE" || type === "MOVED" ? (overrideEndTime || undefined) : undefined,
        overrideInstrumentistId: type === "INSTRUMENTIST_OVERRIDE" ? overrideInstrumentistId : undefined,
      }),
    onSuccess: () => {
      toast.success("Exception ajoutée");
      invalidate();
      setAdding(false);
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteException(id),
    onSuccess: () => { toast.success("Exception supprimée"); invalidate(); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const canSubmit = occurrenceDate !== "" &&
    (type !== "MOVED" || overrideDate !== "") &&
    (type !== "TIME_OVERRIDE" || (overrideStartTime !== "" && overrideEndTime !== ""));

  const items = exceptionsQuery.data?.items ?? [];

  return (
    <Drawer
      anchor="right"
      open={open}
      onClose={onClose}
      slotProps={{ paper: { sx: { width: 420, maxWidth: "92vw", boxShadow: planningV2Shadows.sheet } } }}
    >
      <Stack sx={{ height: "100%" }}>
        <Box sx={{ px: 2.75, py: 2.25, borderBottom: `1px solid ${planningV2Colors.divider}` }}>
          <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={1.5}>
            <Box>
              <Typography sx={{ fontSize: 11, fontWeight: 700, letterSpacing: "0.05em", textTransform: "uppercase", color: planningV2Colors.textSecondary }}>
                Exceptions
              </Typography>
              <Typography sx={{ fontSize: 16, fontWeight: 700, mt: 0.4 }}>
                {post ? (post.surgeon.name ?? post.surgeon.email) : ""}
              </Typography>
              <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, mt: 0.4 }}>
                {post ? `${post.site.name} · une exception ne modifie que cette occurrence` : ""}
              </Typography>
            </Box>
            <IconButton onClick={onClose} sx={{ bgcolor: "#F1F4F7", "&:hover": { bgcolor: "#E7EBEF" }, flex: "none" }}>
              <CloseIcon fontSize="small" />
            </IconButton>
          </Stack>
        </Box>

        <Box sx={{ flex: 1, overflowY: "auto", px: 2.75, py: 2.25, display: "flex", flexDirection: "column", gap: 1.4 }}>
          {exceptionsQuery.isLoading ? (
            <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}><CircularProgress size={24} /></Box>
          ) : exceptionsQuery.isError ? (
            <Typography sx={{ fontSize: 13, color: planningV2Colors.critFg }}>{extractErrorV2(exceptionsQuery.error)}</Typography>
          ) : items.length === 0 && !adding ? (
            <Box sx={{ textAlign: "center", py: 6 }}>
              <Box sx={{ width: 46, height: 46, borderRadius: planningV2Radii.card, bgcolor: "#EFFAF5", display: "flex", alignItems: "center", justifyContent: "center", mx: "auto", mb: 1.5 }}>
                <CheckCircleOutlinedIcon sx={{ fontSize: 22, color: "#2C7D5F" }} />
              </Box>
              <Typography sx={{ fontSize: 14, fontWeight: 600 }}>Aucune exception</Typography>
              <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textSecondary, mt: 0.5, maxWidth: 240, mx: "auto" }}>
                Ce poste suit sa récurrence sans modification.
              </Typography>
            </Box>
          ) : (
            items.map((ex) => {
              const d = new Date(ex.occurrenceDate + "T00:00:00");
              const tokens = TYPE_TOKENS[ex.type];
              const detail =
                ex.type === "MOVED" ? `Déplacé au ${ex.overrideDate}` :
                ex.type === "TIME_OVERRIDE" ? `Nouvel horaire : ${ex.overrideStartTime} – ${ex.overrideEndTime}` :
                ex.type === "INSTRUMENTIST_OVERRIDE" ? `Instrumentiste : ${ex.overrideInstrumentist?.name ?? ex.overrideInstrumentist?.email ?? "—"}` :
                "Occurrence annulée";
              return (
                <Stack key={ex.id} direction="row" spacing={1.5} sx={{ p: 1.75, border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.card }}>
                  <Box sx={{ width: 42, flex: "none", textAlign: "center" }}>
                    <Typography sx={{ fontSize: 18, fontWeight: 800, lineHeight: 1, fontVariantNumeric: "tabular-nums" }}>{d.getDate()}</Typography>
                    <Typography sx={{ fontSize: 10.5, fontWeight: 600, letterSpacing: "0.03em", color: planningV2Colors.textSecondary, mt: 0.25 }}>
                      {MONTHS_SHORT[d.getMonth()]}
                    </Typography>
                  </Box>
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Box component="span" sx={{ display: "inline-block", fontSize: 11, fontWeight: 700, color: tokens.fg, bgcolor: tokens.bg, px: 1, py: 0.25, borderRadius: planningV2Radii.pill }}>
                      {TYPE_LABELS[ex.type]}
                    </Box>
                    <Typography sx={{ fontSize: 13, color: planningV2Colors.textStrong, mt: 0.9 }}>{detail}</Typography>
                  </Box>
                  <IconButton size="small" onClick={() => deleteMutation.mutate(ex.id)} sx={{ color: planningV2Colors.textSecondary, flex: "none" }}>
                    <DeleteOutlineIcon fontSize="small" />
                  </IconButton>
                </Stack>
              );
            })
          )}

          {adding && (
            <Stack spacing={1.5} sx={{ p: 1.75, border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.card, mt: items.length ? 1 : 0 }}>
              <Select size="small" value={type} onChange={(e) => setType(e.target.value as OccurrenceExceptionType)}>
                {Object.entries(TYPE_LABELS).map(([k, label]) => (
                  <MenuItem key={k} value={k}>{label}</MenuItem>
                ))}
              </Select>
              <TextField
                size="small" label="Date de l'occurrence" type="date" fullWidth
                value={occurrenceDate} onChange={(e) => setOccurrenceDate(e.target.value)}
                slotProps={{ inputLabel: { shrink: true } }}
              />
              {type === "MOVED" && (
                <TextField
                  size="small" label="Nouvelle date" type="date" fullWidth
                  value={overrideDate} onChange={(e) => setOverrideDate(e.target.value)}
                  slotProps={{ inputLabel: { shrink: true } }}
                />
              )}
              {(type === "TIME_OVERRIDE" || type === "MOVED") && (
                <Stack direction="row" spacing={1.5}>
                  <TextField size="small" label="Début" type="time" fullWidth value={overrideStartTime} onChange={(e) => setOverrideStartTime(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
                  <TextField size="small" label="Fin" type="time" fullWidth value={overrideEndTime} onChange={(e) => setOverrideEndTime(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
                </Stack>
              )}
              {type === "INSTRUMENTIST_OVERRIDE" && (
                <SearchableSelect label="Nouvelle instrumentiste" options={instrumentists} value={overrideInstrumentistId} onChange={setOverrideInstrumentistId} />
              )}
              <Stack direction="row" spacing={1}>
                <Button size="small" onClick={() => setAdding(false)} sx={{ textTransform: "none" }}>Annuler</Button>
                <Button
                  size="small" variant="contained" disableElevation disabled={!canSubmit || createMutation.isPending}
                  onClick={() => createMutation.mutate()}
                  sx={{ textTransform: "none", bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
                >
                  Ajouter
                </Button>
              </Stack>
            </Stack>
          )}
        </Box>

        {!adding && (
          <Box sx={{ px: 2.75, py: 2, borderTop: `1px solid ${planningV2Colors.divider}` }}>
            <Button
              fullWidth onClick={() => setAdding(true)}
              startIcon={<AddIcon />}
              sx={{
                height: 42, borderRadius: planningV2Radii.button, border: "1.5px dashed #DDE2E8",
                color: planningV2Colors.textBody, textTransform: "none", fontWeight: 600,
                "&:hover": { borderColor: planningV2Colors.brand, color: planningV2Colors.brand },
              }}
            >
              Ajouter une exception
            </Button>
          </Box>
        )}
      </Stack>
    </Drawer>
  );
}
