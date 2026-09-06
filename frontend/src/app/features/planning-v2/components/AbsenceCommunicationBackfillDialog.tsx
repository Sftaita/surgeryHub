import * as React from "react";
import {
  Alert, Box, Button, Checkbox, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, Stack, TextField, Typography,
} from "@mui/material";
import { useMutation } from "@tanstack/react-query";

import { previewAbsenceCommunicationBackfill, executeAbsenceCommunicationBackfill, extractErrorV2 } from "../api/planningV2.api";
import type {
  BackfillAbsenceItemV2, BackfillExecuteResponseV2, BackfillPreviewV2,
} from "../api/planningV2.types";
import { useToast } from "../../../ui/toast/useToast";
import { planningV2Colors, planningV2Radii } from "../theme/tokens";

const ROOM_RELEASE_LABELS: Record<string, string> = {
  WILL_SEND: "Sera envoyé",
  NO_NEW_OCCURRENCE: "Déjà annoncé",
  NO_RECIPIENT: "0 destinataire",
  NO_FUTURE_BLOCK: "Aucun bloc futur",
  DISABLED: "Désactivé",
};

const BLOCK_MANAGEMENT_LABELS: Record<string, string> = {
  WILL_SEND_NOW: "Envoi immédiat",
  WILL_SCHEDULE: "Sera programmé",
  ALREADY_PROCESSED: "Déjà traité",
  DISABLED: "Désactivé",
  MISSING_CONFIG: "Configuration incomplète",
  ABSENCE_ALREADY_ENDED: "Congé déjà terminé",
};

function todayISO(): string {
  return new Date().toISOString().slice(0, 10);
}

type Step = "cutoff" | "preview" | "confirm" | "result";

/**
 * Communication des absences chirurgiens — Lot C (D-114), §24/§25 de la demande. Rattrapage
 * des absences déjà existantes : cutoff → Analyser (preview, purement en lecture) → sélection
 * → confirmation explicite → Traiter (execute, revalidé côté serveur — §9).
 *
 * Sélection au grain de l'absence complète, jamais par site — voir le rapport final pour la
 * justification (§8).
 */
export function AbsenceCommunicationBackfillDialog({
  open, onClose, onExecuted,
}: {
  open: boolean;
  onClose: () => void;
  onExecuted: () => void;
}) {
  const toast = useToast();
  const [step, setStep] = React.useState<Step>("cutoff");
  const [createdFrom, setCreatedFrom] = React.useState(todayISO());
  const [preview, setPreview] = React.useState<BackfillPreviewV2 | null>(null);
  const [selected, setSelected] = React.useState<Set<number>>(new Set());
  const [result, setResult] = React.useState<BackfillExecuteResponseV2 | null>(null);

  const previewMutation = useMutation({
    mutationFn: () => previewAbsenceCommunicationBackfill(createdFrom),
    onSuccess: (data) => {
      setPreview(data);
      setSelected(new Set(data.items.filter((i) => i.selectable).map((i) => i.absenceId)));
      setStep("preview");
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const executeMutation = useMutation({
    mutationFn: () => executeAbsenceCommunicationBackfill(createdFrom, Array.from(selected)),
    onSuccess: (data) => {
      setResult(data);
      setStep("result");
      onExecuted();
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  function handleClose() {
    setStep("cutoff");
    setPreview(null);
    setSelected(new Set());
    setResult(null);
    onClose();
  }

  function toggleAbsence(id: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  return (
    <Dialog open={open} onClose={step === "confirm" || executeMutation.isPending ? undefined : handleClose} maxWidth="md" fullWidth>
      <DialogTitle sx={{ fontWeight: 700 }}>Traiter les absences existantes</DialogTitle>
      <DialogContent dividers>
        {step === "cutoff" && (
          <Stack spacing={2} sx={{ pt: 1 }}>
            <Typography sx={{ fontSize: 13.5, color: planningV2Colors.textMuted }}>
              Rattrape les communications (libération de salle, gestion du bloc) des absences
              déjà encodées avant la mise en place de cette fonctionnalité. Le filtre porte
              strictement sur la date de création de l'absence — jamais sur la période du congé.
            </Typography>
            <TextField
              label="Absences créées à partir du" type="date" size="small"
              value={createdFrom} onChange={(e) => setCreatedFrom(e.target.value)}
              InputLabelProps={{ shrink: true }} sx={{ maxWidth: 260 }}
            />
          </Stack>
        )}

        {step === "preview" && preview && (
          <PreviewStep preview={preview} selected={selected} onToggle={toggleAbsence} />
        )}

        {step === "confirm" && preview && (
          <Alert severity="warning">
            Des emails seront réellement envoyés et des programmations réellement créées pour {selected.size} absence{selected.size > 1 ? "s" : ""} sélectionnée{selected.size > 1 ? "s" : ""}. Cette action n'est pas une simple sauvegarde. Continuer ?
          </Alert>
        )}

        {step === "result" && result && <ResultStep result={result} />}
      </DialogContent>
      <DialogActions>
        {step === "cutoff" && (
          <>
            <Button onClick={handleClose} color="inherit" sx={{ textTransform: "none" }}>Annuler</Button>
            <Button
              variant="contained" disableElevation disabled={!createdFrom || previewMutation.isPending}
              onClick={() => previewMutation.mutate()}
              sx={{ textTransform: "none", bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
            >
              {previewMutation.isPending ? <CircularProgress size={16} sx={{ color: "#fff" }} /> : "Analyser"}
            </Button>
          </>
        )}

        {step === "preview" && (
          <>
            <Button onClick={() => setStep("cutoff")} color="inherit" sx={{ textTransform: "none" }}>Retour</Button>
            <Button
              variant="contained" disableElevation disabled={selected.size === 0}
              onClick={() => setStep("confirm")}
              sx={{ textTransform: "none", bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
            >
              Traiter les absences sélectionnées ({selected.size})
            </Button>
          </>
        )}

        {step === "confirm" && (
          <>
            <Button onClick={() => setStep("preview")} color="inherit" disabled={executeMutation.isPending} sx={{ textTransform: "none" }}>Annuler</Button>
            <Button
              variant="contained" disableElevation color="warning" disabled={executeMutation.isPending}
              onClick={() => { if (!executeMutation.isPending) executeMutation.mutate(); }}
              sx={{ textTransform: "none" }}
            >
              {executeMutation.isPending ? <CircularProgress size={16} sx={{ color: "#fff" }} /> : "Traiter"}
            </Button>
          </>
        )}

        {step === "result" && (
          <>
            <Button onClick={() => setStep("cutoff")} color="inherit" sx={{ textTransform: "none" }}>Relancer une analyse</Button>
            <Button
              variant="contained" disableElevation onClick={handleClose}
              sx={{ textTransform: "none", bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
            >
              Fermer
            </Button>
          </>
        )}
      </DialogActions>
    </Dialog>
  );
}

function PreviewStep({
  preview, selected, onToggle,
}: {
  preview: BackfillPreviewV2;
  selected: Set<number>;
  onToggle: (id: number) => void;
}) {
  const s = preview.summary;
  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
        <SummaryChip label={`${s.eligibleAbsences} absences éligibles`} />
        <SummaryChip label={`${s.ignoredOlderAbsences} ignorées (antérieures)`} muted />
        <SummaryChip label={`${s.roomReleaseEmailsPotential} emails collègues`} />
        <SummaryChip label={`${s.blockManagementImmediate} envois immédiats gestion`} />
        <SummaryChip label={`${s.blockManagementScheduled} programmations gestion`} />
        <SummaryChip label={`${s.alreadyProcessedSites} déjà traités`} muted />
      </Stack>

      {preview.items.length === 0 ? (
        <Alert severity="info">Aucune absence éligible pour ce cutoff.</Alert>
      ) : (
        <Stack spacing={1.25} sx={{ maxHeight: 420, overflowY: "auto" }}>
          {preview.items.map((item) => (
            <AbsenceRow key={item.absenceId} item={item} checked={selected.has(item.absenceId)} onToggle={onToggle} />
          ))}
        </Stack>
      )}
    </Stack>
  );
}

function SummaryChip({ label, muted }: { label: string; muted?: boolean }) {
  return (
    <Chip
      label={label} size="small"
      sx={{ bgcolor: muted ? "#F1F4F7" : planningV2Colors.selectedBg, color: muted ? planningV2Colors.textMuted : planningV2Colors.brand, fontWeight: 600, fontSize: 12 }}
    />
  );
}

function AbsenceRow({
  item, checked, onToggle,
}: {
  item: BackfillAbsenceItemV2;
  checked: boolean;
  onToggle: (id: number) => void;
}) {
  return (
    <Box sx={{ border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.button, p: 1.5, opacity: item.selectable ? 1 : 0.55 }}>
      <Stack direction="row" alignItems="flex-start" spacing={1}>
        <Checkbox
          size="small" checked={checked} disabled={!item.selectable}
          onChange={() => onToggle(item.absenceId)}
          sx={{ p: 0.25, mt: 0.25 }}
        />
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Stack direction="row" justifyContent="space-between" alignItems="center" flexWrap="wrap">
            <Typography sx={{ fontSize: 13.5, fontWeight: 700 }}>
              {item.surgeonName ?? `Absence #${item.absenceId}`}
            </Typography>
            <Typography sx={{ fontSize: 12, color: planningV2Colors.textMuted }}>
              {new Date(item.dateStart + "T00:00:00").toLocaleDateString("fr-BE")} → {new Date(item.dateEnd + "T00:00:00").toLocaleDateString("fr-BE")}
            </Typography>
          </Stack>
          {item.sites.length === 0 ? (
            <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, mt: 0.5 }}>Aucun site concerné.</Typography>
          ) : (
            <Stack spacing={0.5} sx={{ mt: 0.75 }}>
              {item.sites.map((site) => (
                <Stack key={site.siteId} direction="row" spacing={1} alignItems="center" flexWrap="wrap">
                  <Typography sx={{ fontSize: 12.5, fontWeight: 600, minWidth: 140 }}>{site.siteName}</Typography>
                  <Chip label={`Collègues : ${ROOM_RELEASE_LABELS[site.roomRelease.status] ?? site.roomRelease.status}`} size="small" variant="outlined" sx={{ fontSize: 11 }} />
                  <Chip label={`Gestion : ${BLOCK_MANAGEMENT_LABELS[site.blockManagement.status] ?? site.blockManagement.status}`} size="small" variant="outlined" sx={{ fontSize: 11 }} />
                </Stack>
              ))}
            </Stack>
          )}
        </Box>
      </Stack>
    </Box>
  );
}

function ResultStep({ result }: { result: BackfillExecuteResponseV2 }) {
  const processed = result.results.filter((r) => r.status === "PROCESSED").length;
  const skipped = result.results.filter((r) => r.status.startsWith("SKIPPED")).length;
  const errors = result.results.filter((r) => r.status === "ERROR");

  return (
    <Stack spacing={2}>
      <Alert severity={errors.length > 0 ? "warning" : "success"}>
        {result.results.length} absence{result.results.length > 1 ? "s" : ""} traitée{result.results.length > 1 ? "s" : ""} — {processed} traitée{processed > 1 ? "s" : ""} avec succès, {skipped} ignorée{skipped > 1 ? "s" : ""}, {errors.length} erreur{errors.length > 1 ? "s" : ""}.
      </Alert>
      {errors.length > 0 && (
        <Stack spacing={0.75}>
          {errors.map((e) => (
            <Typography key={e.absenceId} sx={{ fontSize: 12.5, color: "error.main" }}>
              Absence #{e.absenceId} : {e.error}
            </Typography>
          ))}
        </Stack>
      )}
    </Stack>
  );
}
