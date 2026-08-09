import * as React from "react";
import { Box, FormControlLabel, Radio, RadioGroup, Stack, TextField, Typography } from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { SheetModal } from "../../../ui/sheet/SheetModal";
import { useToast } from "../../../ui/toast/useToast";
import { createEncodingAnomalyReport } from "../api/encodingAnomalyReports.api";
import {
  ENCODING_ANOMALY_REPORT_TYPES,
  ENCODING_ANOMALY_REPORT_TYPE_LABELS,
  type EncodingAnomalyReportType,
} from "../api/encodingAnomalyReports.types";

const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const RED_600 = "#E5484D";

/**
 * Lot 6 (D-100, §20-22) — sélection du type via boutons radio (jamais un menu
 * déroulant), commentaire obligatoire. Après succès : invalidation de la query des
 * signalements pour un retour visuel immédiat (badge "Problème signalé"), pas de
 * rafraîchissement manuel nécessaire.
 */
export function AnomalyReportSheet({
  open,
  onClose,
  missionId,
}: {
  open: boolean;
  onClose: () => void;
  missionId: number;
}) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [type, setType] = React.useState<EncodingAnomalyReportType>("INTERVENTION_MISSING");
  const [comment, setComment] = React.useState("");

  React.useEffect(() => {
    if (!open) return;
    setType("INTERVENTION_MISSING");
    setComment("");
  }, [open]);

  const mutation = useMutation({
    mutationFn: () => createEncodingAnomalyReport(missionId, { type, comment: comment.trim() }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["encodingAnomalyReports", missionId] });
      toast.success("Signalement envoyé.");
      onClose();
    },
    onError: (err: any) => {
      const status = err?.response?.status;
      if (status === 409) {
        toast.error("Un signalement est déjà en attente pour cette mission.");
      } else {
        toast.error("Impossible d'envoyer le signalement.");
      }
    },
  });

  const hasComment = comment.trim().length > 0;

  return (
    <SheetModal open={open} title="Signaler un problème" onClose={onClose} closeDisabled={mutation.isPending}>
      <Stack spacing={2} sx={{ mt: "18px" }}>
        <Typography sx={{ fontSize: 13, color: GRAY_500 }}>
          Décrivez le problème constaté sur l'encodage de cette mission. Un manager sera notifié.
        </Typography>

        <RadioGroup
          value={type}
          onChange={(e) => setType(e.target.value as EncodingAnomalyReportType)}
        >
          {ENCODING_ANOMALY_REPORT_TYPES.map((t) => (
            <FormControlLabel
              key={t}
              value={t}
              disabled={mutation.isPending}
              control={<Radio size="small" />}
              label={ENCODING_ANOMALY_REPORT_TYPE_LABELS[t]}
            />
          ))}
        </RadioGroup>

        <TextField
          label="Description"
          value={comment}
          onChange={(e) => setComment(e.target.value)}
          multiline
          minRows={3}
          fullWidth
          required
          disabled={mutation.isPending}
          placeholder="Précisez ce qui ne correspond pas à la réalité…"
        />

        {mutation.isError && !((mutation.error as any)?.response?.status === 409) && (
          <Typography sx={{ fontSize: 12.5, color: RED_600 }}>
            Impossible d'envoyer le signalement. Réessayez.
          </Typography>
        )}

        <Box
          component="button"
          type="button"
          onClick={() => mutation.mutate()}
          disabled={!hasComment || mutation.isPending}
          sx={{
            mt: "4px", width: "100%", height: 50, border: "none", borderRadius: "12px",
            background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
            cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
            "&:disabled": { opacity: 0.55, cursor: "default", boxShadow: "none" },
          }}
        >
          {mutation.isPending ? "…" : "Envoyer le signalement"}
        </Box>
        <Box
          component="button"
          type="button"
          onClick={onClose}
          disabled={mutation.isPending}
          sx={{ width: "100%", height: 42, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
        >
          Annuler
        </Box>
      </Stack>
    </SheetModal>
  );
}
