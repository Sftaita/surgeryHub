import * as React from "react";
import {
  Alert, Box, Button, CircularProgress, Dialog, IconButton, Stack, TextField, Typography,
} from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import CheckIcon from "@mui/icons-material/Check";
import { useQuery } from "@tanstack/react-query";

import type { PlanningAlertV2 } from "../api/planningV2.types";
import { getEligibleInstrumentists, extractErrorV2 } from "../api/planningV2.api";
import { avatarColorFor, initialsFor } from "../api/avatarColor";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

type View = "list" | "compare";

interface Props {
  open: boolean;
  onClose: () => void;
  alert: PlanningAlertV2 | null;
  onConfirm: (instrumentistId: number, note: string) => void;
  submitting: boolean;
}

export function ReassignDialog({ open, onClose, alert, onConfirm, submitting }: Props) {
  const [selectedId, setSelectedId] = React.useState<number | null>(null);
  const [note, setNote] = React.useState("");
  const [view, setView] = React.useState<View>("list");

  React.useEffect(() => {
    if (open) {
      setSelectedId(null);
      setNote("");
      setView("list");
    }
  }, [open, alert?.id]);

  const eligibleQuery = useQuery({
    queryKey: ["planning-v2", "alerts", alert?.id, "eligible-instrumentists"],
    queryFn: () => getEligibleInstrumentists(alert!.id),
    enabled: open && !!alert,
  });

  if (!alert) return null;

  const candidates = eligibleQuery.data?.items ?? [];
  const selected = candidates.find((c) => c.id === selectedId) ?? null;

  return (
    <Dialog
      open={open} onClose={onClose} maxWidth="sm" fullWidth
      slotProps={{ paper: { sx: { borderRadius: planningV2Radii.modal, boxShadow: planningV2Shadows.modal, maxHeight: "88vh" } } }}
    >
      <Box sx={{ px: 2.75, py: 2.25, borderBottom: `1px solid ${planningV2Colors.divider}` }}>
        <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={1.5}>
          <Box>
            <Typography sx={{ fontSize: 16, fontWeight: 700 }}>Réassigner une instrumentiste</Typography>
            <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textSecondary, mt: 0.4 }}>
              {alert.mission.startAt?.slice(0, 10)} · {alert.mission.site?.name ?? "—"}
            </Typography>
          </Box>
          <IconButton onClick={onClose} sx={{ bgcolor: "#F1F4F7", "&:hover": { bgcolor: "#E7EBEF" }, flex: "none" }}>
            <CloseIcon fontSize="small" />
          </IconButton>
        </Stack>
        <Stack direction="row" spacing={0.5} sx={{ mt: 1.75, bgcolor: "#F1F4F7", p: 0.5, borderRadius: planningV2Radii.button, width: "fit-content" }}>
          <ViewTab active={view === "list"} onClick={() => setView("list")} label="Liste classée" />
          <ViewTab active={view === "compare"} onClick={() => setView("compare")} label="Comparer" />
        </Stack>
      </Box>

      <Box sx={{ flex: 1, overflowY: "auto", px: 2.75, py: 2, minHeight: 200 }}>
        {eligibleQuery.isLoading ? (
          <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}><CircularProgress size={24} /></Box>
        ) : eligibleQuery.isError ? (
          <Alert severity="error">{extractErrorV2(eligibleQuery.error)}</Alert>
        ) : candidates.length === 0 ? (
          <Alert severity="warning">Aucune instrumentiste éligible trouvée pour ce créneau.</Alert>
        ) : view === "list" ? (
          <Stack spacing={1.1}>
            {candidates.map((c) => {
              const colors = avatarColorFor(c.name);
              const isSelected = c.id === selectedId;
              return (
                <Stack
                  key={c.id} direction="row" alignItems="center" spacing={1.75}
                  onClick={() => setSelectedId(c.id)}
                  sx={{
                    p: 1.5, borderRadius: planningV2Radii.card, cursor: "pointer",
                    border: `1.5px solid ${isSelected ? planningV2Colors.brand : planningV2Colors.cardBorder}`,
                    bgcolor: isSelected ? planningV2Colors.selectedBg : "#fff",
                  }}
                >
                  <Box sx={{ width: 42, height: 42, borderRadius: planningV2Radii.button, flex: "none", bgcolor: colors.bg, color: colors.fg, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 14, fontWeight: 700 }}>
                    {initialsFor(c.name)}
                  </Box>
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography sx={{ fontSize: 14, fontWeight: 700, color: planningV2Colors.textTitle }}>{c.name}</Typography>
                    <Typography sx={{ fontSize: 12, color: planningV2Colors.textMuted, mt: 0.3 }}>
                      {c.sites.length > 0 ? c.sites.join(", ") : c.email}
                    </Typography>
                  </Box>
                  <Box sx={{
                    width: 32, height: 32, borderRadius: "999px", flex: "none", display: "flex", alignItems: "center", justifyContent: "center",
                    border: `2px solid ${isSelected ? planningV2Colors.brand : "#E7EBEF"}`,
                    bgcolor: isSelected ? planningV2Colors.brand : "transparent",
                    color: isSelected ? "#fff" : "transparent",
                  }}>
                    <CheckIcon sx={{ fontSize: 18 }} />
                  </Box>
                </Stack>
              );
            })}
          </Stack>
        ) : (
          <Box sx={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 1.25 }}>
            {candidates.map((c) => {
              const colors = avatarColorFor(c.name);
              const isSelected = c.id === selectedId;
              return (
                <Box
                  key={c.id} onClick={() => setSelectedId(c.id)}
                  sx={{
                    p: 1.5, borderRadius: planningV2Radii.card, cursor: "pointer", position: "relative",
                    border: `1.5px solid ${isSelected ? planningV2Colors.brand : planningV2Colors.cardBorder}`,
                    bgcolor: isSelected ? planningV2Colors.selectedBg : "#fff",
                  }}
                >
                  <Box sx={{ width: 46, height: 46, borderRadius: planningV2Radii.button, bgcolor: colors.bg, color: colors.fg, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 15, fontWeight: 700, mb: 1.25 }}>
                    {initialsFor(c.name)}
                  </Box>
                  <Typography sx={{ fontSize: 13.5, fontWeight: 700 }}>{c.name}</Typography>
                  <Typography sx={{ fontSize: 11, color: planningV2Colors.textSecondary, mt: 0.3, mb: 1 }}>
                    {c.sites.length > 0 ? c.sites.join(", ") : c.email}
                  </Typography>
                </Box>
              );
            })}
          </Box>
        )}
      </Box>

      <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={1.5}
        sx={{ px: 2.75, py: 2, borderTop: `1px solid ${planningV2Colors.divider}`, bgcolor: "#F8FAFC" }}
      >
        <TextField
          size="small" placeholder="Note (optionnel)" value={note} onChange={(e) => setNote(e.target.value)}
          sx={{ flex: 1, maxWidth: 280, "& .MuiOutlinedInput-root": { borderRadius: planningV2Radii.button, bgcolor: "#fff" } }}
        />
        <Stack direction="row" spacing={1.25}>
          <Button onClick={onClose} sx={{ height: 40, px: 2, borderRadius: planningV2Radii.button, border: "1px solid #DDE2E8", color: planningV2Colors.textStrong, textTransform: "none", fontWeight: 600 }}>
            Annuler
          </Button>
          <Button
            variant="contained" disableElevation disabled={selected === null || submitting}
            onClick={() => selected && onConfirm(selected.id, note)}
            sx={{ height: 40, px: 2.25, borderRadius: planningV2Radii.button, textTransform: "none", fontWeight: 600, bgcolor: planningV2Colors.brand, boxShadow: planningV2Shadows.button, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
          >
            Confirmer la réassignation
          </Button>
        </Stack>
      </Stack>
    </Dialog>
  );
}

function ViewTab({ active, onClick, label }: { active: boolean; onClick: () => void; label: string }) {
  return (
    <Box
      component="button" onClick={onClick}
      sx={{
        border: "none", cursor: "pointer", fontFamily: "inherit", fontSize: 12.5, fontWeight: 700,
        px: 1.5, py: 0.75, borderRadius: "7px",
        bgcolor: active ? "#fff" : "transparent", color: active ? planningV2Colors.textTitle : planningV2Colors.textSecondary,
        boxShadow: active ? "0 1px 2px rgba(22,32,43,.08)" : "none",
      }}
    >
      {label}
    </Box>
  );
}
