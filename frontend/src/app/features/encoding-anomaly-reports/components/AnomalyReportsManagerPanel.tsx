import * as React from "react";
import {
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Paper,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";

import { useToast } from "../../../ui/toast/useToast";
import { fetchEncodingAnomalyReports, resolveEncodingAnomalyReport } from "../api/encodingAnomalyReports.api";
import { ENCODING_ANOMALY_REPORT_TYPE_LABELS, type EncodingAnomalyReport } from "../api/encodingAnomalyReports.types";

/**
 * Lot 6 (D-100, §15-17) — intégrée dans le détail Mission existant (jamais une
 * nouvelle page de listing top-level, cf. audit UX). Résoudre marque uniquement le
 * signalement comme traité — ne mute jamais l'encodage lui-même (§17), la correction
 * continue de passer par les workflows existants (édition instrumentiste, reject/reopen).
 */
export function AnomalyReportsManagerPanel({ missionId }: { missionId: number }) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [resolvingReport, setResolvingReport] = React.useState<EncodingAnomalyReport | null>(null);
  const [resolutionComment, setResolutionComment] = React.useState("");

  const { data: reports } = useQuery({
    queryKey: ["encodingAnomalyReports", missionId],
    queryFn: () => fetchEncodingAnomalyReports(missionId),
    enabled: Number.isFinite(missionId) && missionId > 0,
  });

  const resolveMutation = useMutation({
    mutationFn: (report: EncodingAnomalyReport) =>
      resolveEncodingAnomalyReport(report.id, { resolutionComment: resolutionComment.trim() }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["encodingAnomalyReports", missionId] });
      toast.success("Signalement traité.");
      setResolvingReport(null);
      setResolutionComment("");
    },
    onError: (err: any) => {
      if (err?.response?.status === 409) {
        toast.error("Ce signalement a déjà été traité.");
        queryClient.invalidateQueries({ queryKey: ["encodingAnomalyReports", missionId] });
        setResolvingReport(null);
      } else {
        toast.error("Impossible de traiter ce signalement.");
      }
    },
  });

  if (!reports || reports.length === 0) return null;

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
      <Typography variant="subtitle2" fontWeight={600} mb={1.5}>
        Anomalies signalées par le chirurgien
      </Typography>
      <Stack spacing={1.5}>
        {reports.map((r) => (
          <Box key={r.id} sx={{ pb: 1.5, borderBottom: "1px solid", borderColor: "grey.100", "&:last-of-type": { borderBottom: "none", pb: 0 } }}>
            <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={1}>
              <Box>
                <Typography variant="body2" fontWeight={600}>
                  {ENCODING_ANOMALY_REPORT_TYPE_LABELS[r.type]}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {r.reporter?.displayName ?? "—"} · {dayjs(r.createdAt).format("D MMM YYYY HH:mm")}
                </Typography>
              </Box>
              <Chip
                label={r.status === "OPEN" ? "En attente" : "Traité"}
                color={r.status === "OPEN" ? "warning" : "success"}
                size="small"
              />
            </Stack>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, whiteSpace: "pre-wrap" }}>
              {r.comment}
            </Typography>
            {r.status === "RESOLVED" && r.resolutionComment && (
              <Typography variant="body2" sx={{ mt: 0.5, fontStyle: "italic" }}>
                Réponse : {r.resolutionComment}
              </Typography>
            )}
            {r.status === "OPEN" && (
              <Button
                size="small"
                variant="outlined"
                sx={{ mt: 1 }}
                onClick={() => { setResolvingReport(r); setResolutionComment(""); }}
              >
                Marquer comme traité
              </Button>
            )}
          </Box>
        ))}
      </Stack>

      <Dialog open={resolvingReport !== null} onClose={() => setResolvingReport(null)}>
        <DialogTitle>Marquer le signalement comme traité</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary" mb={1.5}>
            Résoudre ne corrige pas automatiquement l'encodage — corrigez-le séparément si nécessaire.
          </Typography>
          <TextField
            label="Commentaire de résolution"
            value={resolutionComment}
            onChange={(e) => setResolutionComment(e.target.value)}
            multiline
            minRows={2}
            fullWidth
            required
            disabled={resolveMutation.isPending}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setResolvingReport(null)} disabled={resolveMutation.isPending}>
            Annuler
          </Button>
          <Button
            variant="contained"
            disabled={resolutionComment.trim().length === 0 || resolveMutation.isPending}
            onClick={() => resolvingReport && resolveMutation.mutate(resolvingReport)}
          >
            Confirmer
          </Button>
        </DialogActions>
      </Dialog>
    </Paper>
  );
}
