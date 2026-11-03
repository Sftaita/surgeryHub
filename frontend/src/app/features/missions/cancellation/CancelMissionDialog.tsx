import * as React from "react";
import {
  Alert, Box, Button, Checkbox, Chip, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle,
  FormControlLabel, Radio, RadioGroup, Stack, TextField, Typography,
} from "@mui/material";
import CheckIcon from "@mui/icons-material/Check";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { cancelMission, fetchMissionById } from "../api/missions.api";
import type { Mission } from "../api/missions.types";
import { formatMissionStatus } from "../utils/missions.format";
import { invalidateOperationalPlanning } from "../dispatch/missionDispatch.api";
import { hhmm, missionDateLabel, personName } from "../reassignment/ChangeInstrumentistDialog";
import { ReassignmentNotificationStatus } from "../reassignment/ReassignmentNotificationStatus";
import { cancelAsReplaced, fetchReplacementCandidates, type CancelAsReplacedResult, type ReplacementCandidate } from "./cancellation.api";

type Reason = "PROGRAM_CANCELLED" | "REPLACED_BY_MISSION";

interface Props {
  missionId: number | null;
  open: boolean;
  onClose: () => void;
  /** Après une annulation réussie (ex. quitter le détail d'une mission désormais annulée). */
  onCancelled?: () => void;
}

function serverMessage(err: unknown): string {
  const e = err as { response?: { status?: number; data?: { error?: { message?: string } } } };
  if (e?.response?.status === 403) return "Accès interdit.";
  return e?.response?.data?.error?.message ?? "L'annulation n'a pas pu être enregistrée. Réessayez.";
}

/**
 * D-182 — « Annuler la mission », depuis son détail ou le planning manager, en deux clics :
 *
 * - Programme annulé : la mission n'a pas eu lieu (aucune heure, aucune prestation, aucune
 *   facturation) ; l'instrumentiste et le chirurgien reçoivent l'e-mail d'annulation.
 * - Mission remplacée par une autre mission : une mission existante (ex. créée par la
 *   remplaçante) est conservée à sa place, sa déclaration validée si le manager le demande ;
 *   la mission est annulée comme doublon en une seule opération, et le chirurgien est informé
 *   d'un changement d'instrumentiste, jamais d'une annulation.
 *
 * Encodage soumis ou validé (non facturé) : la correction tracée (renvoi en correction,
 * réouverture) est faite d'abord, dans la même opération, une fois cochée par le manager —
 * jamais un aller-retour par le Suivi des encodages. Une mission que le serveur refuse
 * d'annuler n'ouvre pas cette fenêtre : l'action est désactivée avec sa raison.
 *
 * Tout ce qui est décidé vient du serveur : possibilité ou chemin de correction
 * (`cancellation`), missions compatibles, contrôle final, notifications et leur statut réel.
 */
export function CancelMissionDialog({ missionId, open, onClose, onCancelled }: Props) {
  const queryClient = useQueryClient();
  const [reason, setReason] = React.useState<Reason>("PROGRAM_CANCELLED");
  const [message, setMessage] = React.useState("");
  const [comment, setComment] = React.useState("");
  const [replacementId, setReplacementId] = React.useState<number | null>(null);
  const [approve, setApprove] = React.useState(true);
  const [correctionConfirmed, setCorrectionConfirmed] = React.useState(false);
  const [replacedResult, setReplacedResult] = React.useState<CancelAsReplacedResult | null>(null);

  React.useEffect(() => {
    if (open) {
      setReason("PROGRAM_CANCELLED");
      setMessage("");
      setComment("");
      setReplacementId(null);
      setApprove(true);
      setCorrectionConfirmed(false);
      setReplacedResult(null);
    }
  }, [open, missionId]);

  const missionQuery = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId!),
    enabled: open && missionId !== null,
  });
  const mission: Mission | undefined = missionQuery.data;
  const done = replacedResult !== null;
  // Après l'opération, la mission rechargée est annulée : son refus n'a plus rien à expliquer.
  const blocked = done ? null : mission?.cancellation?.blockedReason ?? null;
  // Encodage soumis / validé : corrigé d'abord, dans la même opération, une fois confirmé.
  const correction = done || blocked ? null : mission?.cancellation?.correction ?? null;

  const candidatesQuery = useQuery({
    queryKey: ["mission-replacement-candidates", missionId],
    queryFn: () => fetchReplacementCandidates(missionId!),
    enabled: open && missionId !== null && reason === "REPLACED_BY_MISSION" && mission !== undefined && blocked === null,
    staleTime: 0,
  });
  const candidates: ReplacementCandidate[] = candidatesQuery.data ?? [];
  const replacement = candidates.find((c) => c.id === replacementId) ?? null;

  const refresh = async (res?: CancelAsReplacedResult) => {
    await invalidateOperationalPlanning(queryClient, missionId);
    await queryClient.invalidateQueries({ queryKey: ["mission-audit", missionId] });
    if (res) {
      await invalidateOperationalPlanning(queryClient, res.replacementMissionId);
      await queryClient.invalidateQueries({ queryKey: ["mission-audit", res.replacementMissionId] });
    }
  };

  const simpleMutation = useMutation({
    mutationFn: () => cancelMission(missionId!, message, comment, correction !== null && correctionConfirmed),
    onSuccess: async () => {
      await refresh();
      onClose();
      onCancelled?.();
    },
  });

  const replacedMutation = useMutation({
    mutationFn: () => cancelAsReplaced(missionId!, {
      replacementMissionId: replacement!.id,
      approveReplacement: replacement!.declared && approve,
      expectedReplacementStatus: replacement!.status,
      internalComment: comment,
      correctionConfirmed: correction !== null && correctionConfirmed,
    }),
    onSuccess: async (res) => {
      setReplacedResult(res);
      await refresh(res);
    },
  });

  const pending = simpleMutation.isPending || replacedMutation.isPending;
  const error = simpleMutation.error ?? replacedMutation.error;
  const canConfirm = mission !== undefined && blocked === null && !pending && !done
    && (reason === "PROGRAM_CANCELLED" || replacement !== null)
    && (correction === null || correctionConfirmed);

  const confirm = () => {
    simpleMutation.reset();
    replacedMutation.reset();
    if (reason === "PROGRAM_CANCELLED") simpleMutation.mutate();
    else replacedMutation.mutate();
  };

  const instrumentistName = mission?.instrumentist ? personName(mission.instrumentist) : null;
  const replacementName = replacement ? personName(replacement.instrumentist) : null;

  return (
    <Dialog open={open} onClose={pending ? undefined : onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Annuler la mission</DialogTitle>
      <DialogContent dividers>
        {missionQuery.isLoading && <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={24} /></Box>}
        {missionQuery.isError && <Alert severity="error">Impossible de charger la mission.</Alert>}

        {mission && (
          <Stack spacing={2}>
            <Box component="dl" sx={{ m: 0, display: "grid", gridTemplateColumns: "max-content 1fr", columnGap: 2, rowGap: 0.5, "& dt": { color: "text.secondary" }, "& dd": { m: 0, fontWeight: 600 } }} data-testid="cancel-mission-summary">
              <dt>Date</dt><dd>{missionDateLabel(mission.startAt)}</dd>
              <dt>Site</dt><dd>{mission.site?.name ?? "—"}{mission.roomLabel ? ` · ${mission.roomLabel}` : ""}</dd>
              <dt>Chirurgien</dt><dd>Dr {personName(mission.surgeon)}</dd>
              <dt>Horaire prévu</dt><dd>{hhmm(mission.startAt)} – {hhmm(mission.endAt)}</dd>
              <dt>Instrumentiste</dt><dd>{instrumentistName ?? "—"}</dd>
            </Box>

            {blocked && (
              <Alert severity="info" data-testid="cancel-mission-blocked">{blocked.message}</Alert>
            )}

            {!blocked && !done && (
              <>
                {correction && (
                  <Alert severity="warning" data-testid="cancel-mission-correction">
                    <Typography variant="body2">{correction.message}</Typography>
                    <FormControlLabel
                      sx={{ mt: 0.5 }}
                      control={<Checkbox size="small" checked={correctionConfirmed} onChange={(e) => setCorrectionConfirmed(e.target.checked)}
                        inputProps={{ "data-testid": "confirm-correction" } as React.InputHTMLAttributes<HTMLInputElement>} />}
                      label={correction.action === "REJECT"
                        ? "Je confirme le renvoi de l'encodage en correction, puis l'annulation"
                        : "Je confirme la réouverture de l'encodage, puis l'annulation"}
                    />
                  </Alert>
                )}

                <RadioGroup value={reason} onChange={(e) => setReason(e.target.value as Reason)} aria-label="Motif d'annulation">
                  <FormControlLabel value="PROGRAM_CANCELLED" control={<Radio size="small" />} data-testid="reason-program-cancelled"
                    label={<><strong>Programme annulé</strong> — la mission n'a pas eu lieu</>} />
                  <FormControlLabel value="REPLACED_BY_MISSION" control={<Radio size="small" />} data-testid="reason-replaced"
                    label={<><strong>Mission remplacée par une autre mission</strong> — une mission existante est conservée</>} />
                </RadioGroup>

                <Typography variant="body2" color="text.secondary">
                  La mission est conservée dans l'historique avec le statut « Annulée ». Aucune heure, intervention ni matériel
                  n'est comptabilisé ni facturé.
                  {mission.cancellation?.hasEncodingData && " L'encodage déjà saisi est archivé pour la traçabilité, sans aucune incidence financière."}
                </Typography>

                {reason === "PROGRAM_CANCELLED" && (
                  <TextField
                    label="Motif communiqué (facultatif)" size="small" multiline minRows={2} value={message}
                    onChange={(e) => setMessage(e.target.value)}
                    helperText="Repris dans l'e-mail d'annulation."
                    inputProps={{ maxLength: 500, "data-testid": "cancel-message" }}
                  />
                )}

                {reason === "REPLACED_BY_MISSION" && (
                  <Stack spacing={0.75}>
                    <Typography variant="subtitle2" fontWeight={700}>Mission conservée</Typography>
                    {candidatesQuery.isLoading && <CircularProgress size={20} />}
                    {candidatesQuery.isError && <Alert severity="error">Impossible de charger les missions compatibles.</Alert>}
                    {candidatesQuery.data && candidates.length === 0 && (
                      <Alert severity="warning" data-testid="replacement-none">
                        Aucune mission compatible : même jour, même site, même chirurgien et horaires qui se recouvrent.
                      </Alert>
                    )}
                    <Stack spacing={0.75} sx={{ maxHeight: 260, overflowY: "auto" }} data-testid="replacement-candidates">
                      {candidates.map((c) => {
                        const isSelected = c.id === replacementId;
                        return (
                          <Stack
                            key={c.id} direction="row" alignItems="center" spacing={1}
                            onClick={() => setReplacementId(c.id)} data-testid={`replacement-${c.id}`}
                            sx={{
                              p: 1, borderRadius: 1.5, border: "1px solid", cursor: "pointer",
                              borderColor: isSelected ? "primary.main" : "divider", bgcolor: isSelected ? "action.selected" : "transparent",
                            }}
                          >
                            <Box sx={{ flex: 1, minWidth: 0 }}>
                              <Typography variant="body2" fontWeight={600}>{personName(c.instrumentist)}</Typography>
                              <Typography variant="caption" color="text.secondary">
                                Mission #{c.id} · {hhmm(c.startAt)} – {hhmm(c.endAt)}{c.roomLabel ? ` · ${c.roomLabel}` : ""}
                              </Typography>
                            </Box>
                            <Chip size="small" label={c.declared ? "Déclarée — à valider" : formatMissionStatus(c.status)}
                              color={c.declared ? "warning" : "default"} variant="outlined" sx={{ fontSize: 10, height: 20 }} />
                            {isSelected && <CheckIcon fontSize="small" color="primary" />}
                          </Stack>
                        );
                      })}
                    </Stack>
                    {replacement?.declared && (
                      <FormControlLabel
                        control={<Checkbox size="small" checked={approve} onChange={(e) => setApprove(e.target.checked)} inputProps={{ "data-testid": "approve-replacement" } as React.InputHTMLAttributes<HTMLInputElement>} />}
                        label={`Valider la déclaration de ${replacementName}`}
                      />
                    )}
                  </Stack>
                )}

                <TextField
                  label="Commentaire interne (facultatif)" size="small" multiline minRows={2} value={comment}
                  onChange={(e) => setComment(e.target.value)}
                  helperText="Visible uniquement dans l'historique de la mission, jamais dans les e-mails."
                  inputProps={{ maxLength: 1000, "data-testid": "cancel-comment" }}
                />

                <Alert severity="info" icon={false} data-testid="cancel-mission-recap">
                  {reason === "PROGRAM_CANCELLED" ? (
                    <>
                      Recevront l'e-mail d'annulation :{" "}
                      {instrumentistName ? <><strong>{instrumentistName}</strong> et </> : null}
                      <strong>Dr {personName(mission.surgeon)}</strong>.
                    </>
                  ) : (
                    <>
                      Seront informés automatiquement :{" "}
                      {instrumentistName ? <><strong>{instrumentistName}</strong> (mission retirée de son planning), </> : null}
                      {replacement?.declared && approve ? <><strong>{replacementName}</strong> (mission conservée et validée), </> : null}
                      <strong>Dr {personName(mission.surgeon)}</strong> (changement d'instrumentiste, pas d'annulation du programme).
                    </>
                  )}
                </Alert>
              </>
            )}

            {error && <Alert severity="error" data-testid="cancel-mission-error">{serverMessage(error)}</Alert>}

            {done && replacedResult && (
              <Stack spacing={1.5}>
                <Alert severity="success" data-testid="cancel-mission-done">
                  Mission annulée comme doublon. La mission #{replacedResult.replacementMissionId} de {replacementName ?? "la remplaçante"} est conservée
                  {replacedResult.approved ? " et validée." : "."}
                </Alert>
                <ReassignmentNotificationStatus result={{ operationId: replacedResult.operationId, results: [], recipients: replacedResult.recipients }} />
              </Stack>
            )}
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        {done || blocked ? (
          <Button onClick={() => { onClose(); if (done) onCancelled?.(); }}>Fermer</Button>
        ) : (
          <>
            <Button onClick={onClose} disabled={pending}>Retour</Button>
            <Button color="error" variant="contained" disableElevation disabled={!canConfirm} onClick={confirm} data-testid="confirm-cancel-mission">
              {pending ? "Enregistrement…" : "Confirmer l'annulation"}
            </Button>
          </>
        )}
      </DialogActions>
    </Dialog>
  );
}
