import * as React from "react";
import {
  Alert, Box, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle,
  MenuItem, Paper, Select, Stack, TextField, Typography,
} from "@mui/material";
import { useMutation, useQueries, useQueryClient } from "@tanstack/react-query";
import { fetchMissionById } from "../api/missions.api";
import type { Mission } from "../api/missions.types";
import { fetchMissionEligibleInstrumentists } from "../../planning-v2/api/planningV2.api";
import { candidateGhostLabel } from "../../planning-v2/api/eligibilityReasons";
import { invalidateOperationalPlanning } from "../dispatch/missionDispatch.api";
import { reassignMissions, relayInstant, type ReassignmentMode, type ReassignmentResult } from "./reassignment.api";
import { ReassignmentNotificationStatus } from "./ReassignmentNotificationStatus";
import { hhmm, missionDateLabel, personName, reassignmentErrorText } from "./ChangeInstrumentistDialog";

interface Choice {
  instrumentistId: number | "";
  mode: ReassignmentMode | null;
  relayTime: string;
}

interface Props {
  missionIds: number[];
  open: boolean;
  onClose: () => void;
  onDone?: () => void;
}

/**
 * D-181 — réaffectation multiple (absence imprévue d'une journée…) : chaque mission reçoit sa
 * remplaçante, un récapitulatif est relu avant confirmation, puis le serveur contrôle et
 * enregistre chaque mission seule et dit ce qui a réussi ou échoué. Une seule opération :
 * un seul e-mail récapitulatif par personne concernée.
 */
export function BatchReassignmentDialog({ missionIds, open, onClose, onDone }: Props) {
  const queryClient = useQueryClient();
  const [step, setStep] = React.useState<"choose" | "recap" | "result">("choose");
  const [choices, setChoices] = React.useState<Record<number, Choice>>({});
  const [comment, setComment] = React.useState("");
  const [result, setResult] = React.useState<ReassignmentResult | null>(null);

  React.useEffect(() => {
    if (open) {
      setStep("choose");
      setChoices({});
      setComment("");
      setResult(null);
    }
  }, [open]);

  const missionQueries = useQueries({
    queries: missionIds.map((id) => ({ queryKey: ["mission", id], queryFn: () => fetchMissionById(id), enabled: open })),
  });
  const candidateQueries = useQueries({
    queries: missionIds.map((id) => ({ queryKey: ["mission-eligibility", id], queryFn: () => fetchMissionEligibleInstrumentists(id), enabled: open, staleTime: 0 })),
  });

  const missions = missionQueries.map((q) => q.data).filter((m): m is Mission => m !== undefined);
  const loading = missionQueries.some((q) => q.isLoading);

  const choiceOf = (m: Mission): Choice => choices[m.id] ?? { instrumentistId: "", mode: m.reassignment?.modes[0] ?? null, relayTime: "" };
  const setChoice = (m: Mission, patch: Partial<Choice>) => setChoices((prev) => ({ ...prev, [m.id]: { ...choiceOf(m), ...patch } }));
  const candidatesOf = (m: Mission) => candidateQueries[missionIds.indexOf(m.id)]?.data?.candidates.filter((c) => c.id !== m.instrumentist?.id) ?? null;
  const nameOf = (m: Mission, id: number | "") => candidatesOf(m)?.find((c) => c.id === id)?.name ?? "—";

  const reassignable = missions.filter((m) => (m.reassignment?.modes.length ?? 0) > 0);
  const chosen = reassignable.filter((m) => {
    const c = choiceOf(m);
    return c.instrumentistId !== "" && c.mode !== null && (c.mode !== "RELAY" || c.relayTime !== "");
  });

  const mutation = useMutation({
    mutationFn: () => reassignMissions(chosen.map((m) => {
      const c = choiceOf(m);
      return {
        missionId: m.id,
        instrumentistId: c.instrumentistId as number,
        mode: c.mode!,
        ...(c.mode === "RELAY" ? { relayAt: relayInstant(m.startAt, c.relayTime) } : {}),
        expectedInstrumentistId: m.instrumentist?.id ?? null,
      };
    }), comment),
    onSuccess: async (res) => {
      setResult(res);
      setStep("result");
      await Promise.all([
        invalidateOperationalPlanning(queryClient),
        ...res.results.flatMap((r) => [r.missionId, r.relayMissionId ?? null]).filter((id): id is number => !!id)
          .map((id) => invalidateOperationalPlanning(queryClient, id)),
      ]);
      onDone?.();
    },
  });

  const missionLine = (m: Mission) => `${missionDateLabel(m.startAt)} · ${hhmm(m.startAt)}–${hhmm(m.endAt)} · ${m.site?.name ?? "—"} · Dr ${personName(m.surgeon)}`;

  return (
    <Dialog open={open} onClose={mutation.isPending ? undefined : onClose} maxWidth="md" fullWidth>
      <DialogTitle>Réaffecter {missionIds.length > 1 ? `${missionIds.length} missions` : "la mission"}</DialogTitle>
      <DialogContent dividers>
        {loading && <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={24} /></Box>}

        {!loading && step === "choose" && (
          <Stack spacing={1.5}>
            {missions.map((m) => {
              const info = m.reassignment;
              const c = choiceOf(m);
              const candidates = candidatesOf(m);
              return (
                <Paper key={m.id} variant="outlined" sx={{ p: 1.5, borderRadius: 2 }} data-testid={`batch-row-${m.id}`}>
                  <Typography variant="body2" fontWeight={700}>{missionLine(m)}</Typography>
                  <Typography variant="caption" color="text.secondary">Actuellement : {personName(m.instrumentist)}</Typography>
                  {(info?.modes.length ?? 0) === 0 ? (
                    <Alert severity="info" sx={{ mt: 1 }}>{info?.blockedReason?.message ?? "Cette mission ne peut pas être réaffectée."}</Alert>
                  ) : (
                    <Stack direction={{ xs: "column", sm: "row" }} spacing={1} sx={{ mt: 1 }} alignItems={{ sm: "center" }}>
                      <Select
                        size="small" displayEmpty value={c.instrumentistId} sx={{ minWidth: 240 }}
                        onChange={(e) => setChoice(m, { instrumentistId: e.target.value as number | "" })}
                        inputProps={{ "data-testid": `batch-select-${m.id}` }}
                      >
                        <MenuItem value=""><em>Choisir l'instrumentiste de remplacement</em></MenuItem>
                        {(candidates ?? []).sort((a, b) => Number(b.selectable) - Number(a.selectable)).map((cand) => (
                          <MenuItem key={cand.id} value={cand.id} disabled={!cand.selectable} data-testid={`batch-option-${m.id}-${cand.id}`}>
                            {cand.name}{!cand.selectable ? ` — ${candidateGhostLabel(cand) ?? ""}` : ""}
                          </MenuItem>
                        ))}
                      </Select>
                      {(info?.modes.length ?? 0) > 1 && (
                        <Select size="small" value={c.mode ?? ""} onChange={(e) => setChoice(m, { mode: e.target.value as ReassignmentMode })}>
                          <MenuItem value="DIRECT">Toute la mission</MenuItem>
                          <MenuItem value="RELAY">Relais en cours de mission</MenuItem>
                        </Select>
                      )}
                      {c.mode === "RELAY" && (
                        <TextField
                          type="time" size="small" label="Heure du relais" value={c.relayTime} InputLabelProps={{ shrink: true }}
                          onChange={(e) => setChoice(m, { relayTime: e.target.value })}
                          helperText={info?.relayWindow ? `Entre ${hhmm(info.relayWindow.from)} et ${hhmm(info.relayWindow.to)}` : undefined}
                        />
                      )}
                    </Stack>
                  )}
                </Paper>
              );
            })}
            <TextField
              label="Commentaire interne (facultatif)" size="small" multiline minRows={2} value={comment}
              onChange={(e) => setComment(e.target.value)}
              helperText="Visible uniquement dans l'historique des missions, jamais dans les e-mails."
              inputProps={{ maxLength: 1000 }}
            />
          </Stack>
        )}

        {step === "recap" && (
          <Stack spacing={1} data-testid="batch-recap">
            <Typography variant="subtitle2" fontWeight={700}>Récapitulatif des changements</Typography>
            {chosen.map((m) => {
              const c = choiceOf(m);
              return (
                <Typography key={m.id} variant="body2">
                  {missionLine(m)} — <strong>{personName(m.instrumentist)}</strong> → <strong>{nameOf(m, c.instrumentistId)}</strong>
                  {c.mode === "RELAY" ? ` (relais à ${c.relayTime})` : ""}
                </Typography>
              );
            })}
            {reassignable.length > chosen.length && (
              <Alert severity="warning">{reassignable.length - chosen.length} mission(s) sans remplacement choisi ne seront pas modifiées.</Alert>
            )}
            <Alert severity="info" icon={false}>
              Chaque personne concernée (anciennes et nouvelles instrumentistes, chirurgiens) recevra un seul e-mail récapitulant ses missions.
            </Alert>
          </Stack>
        )}

        {step === "result" && result && (
          <Stack spacing={1.5} data-testid="batch-result">
            {result.results.map((r) => {
              const m = missions.find((x) => x.id === r.missionId);
              return (
                <Alert key={String(r.missionId)} severity={r.status === "DONE" ? "success" : "error"} data-testid={`batch-result-${r.missionId}`}>
                  {m ? missionLine(m) : `Mission #${r.missionId}`} — {r.status === "DONE" ? "réaffectée" : reassignmentErrorText(r.error)}
                </Alert>
              );
            })}
            <ReassignmentNotificationStatus result={result} />
          </Stack>
        )}

        {mutation.isError && <Alert severity="error" sx={{ mt: 1 }}>L'opération n'a pas pu être envoyée. Rien n'a été modifié ; réessayez.</Alert>}
      </DialogContent>
      <DialogActions>
        {step === "choose" && (
          <>
            <Button onClick={onClose}>Annuler</Button>
            <Button variant="contained" disableElevation disabled={chosen.length === 0} onClick={() => setStep("recap")} data-testid="batch-to-recap">
              Vérifier les changements
            </Button>
          </>
        )}
        {step === "recap" && (
          <>
            <Button onClick={() => setStep("choose")} disabled={mutation.isPending}>Retour</Button>
            <Button variant="contained" disableElevation disabled={mutation.isPending} onClick={() => mutation.mutate()} data-testid="batch-confirm">
              {mutation.isPending ? "Enregistrement…" : "Confirmer la réaffectation"}
            </Button>
          </>
        )}
        {step === "result" && <Button onClick={onClose}>Fermer</Button>}
      </DialogActions>
    </Dialog>
  );
}
