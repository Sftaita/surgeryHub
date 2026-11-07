import * as React from "react";
import {
  Alert, Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle,
  Stack, TextField, ToggleButton, ToggleButtonGroup, Typography,
} from "@mui/material";
import CheckIcon from "@mui/icons-material/Check";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { fetchMissionById } from "../api/missions.api";
import type { Mission } from "../api/missions.types";
import { fetchMissionEligibleInstrumentists } from "../../planning-v2/api/planningV2.api";
import { eligibilityReasonLabel } from "../../planning-v2/api/eligibilityReasons";
import type { CandidateEligibility, EligibilityReason } from "../../planning-v2/api/planningV2.types";
import { invalidateOperationalPlanning } from "../dispatch/missionDispatch.api";
import {
  reassignMissions, relayInstant,
  type ReassignmentMode, type ReassignmentResult, type ReassignmentResultItem,
} from "./reassignment.api";
import { ReassignmentNotificationStatus } from "./ReassignmentNotificationStatus";

export function personName(u: { firstname?: string | null; lastname?: string | null; email?: string } | null | undefined): string {
  if (!u) return "—";
  const n = `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim();
  return n || u.email || "—";
}

export function missionDateLabel(iso: string): string {
  const s = new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString("fr-BE", { weekday: "long", day: "2-digit", month: "2-digit", year: "numeric" });
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/** Heure murale servie par le serveur (Europe/Brussels), jamais reconvertie par le navigateur. */
export const hhmm = (iso: string) => iso.slice(11, 16);

export function reassignmentErrorText(error: ReassignmentResultItem["error"]): string {
  if (!error) return "Changement impossible.";
  if (error.reasons && error.reasons.length > 0) {
    return `${error.message} (${error.reasons.map((r) => eligibilityReasonLabel(r as EligibilityReason)).join(", ")})`;
  }
  return error.message;
}

function sortedCandidates(candidates: CandidateEligibility[], currentId: number | null): CandidateEligibility[] {
  return candidates
    .filter((c) => c.id !== currentId)
    .sort((a, b) => Number(b.selectable) - Number(a.selectable) || a.name.localeCompare(b.name, "fr"));
}

interface Props {
  missionId: number | null;
  open: boolean;
  onClose: () => void;
}

/**
 * D-181 — « Changer d'instrumentiste » d'une mission publiée, depuis son détail (avec ou sans
 * alerte). Tout ce qui est décidé vient du serveur : modes possibles et refus expliqué
 * (`reassignment`), candidats et raisons (eligible-instrumentists), contrôle final, relais,
 * notifications et leur statut réel. Le commentaire interne n'est jamais transmis aux
 * personnes informées.
 */
export function ChangeInstrumentistDialog({ missionId, open, onClose }: Props) {
  const queryClient = useQueryClient();
  const [selected, setSelected] = React.useState<number | null>(null);
  const [mode, setMode] = React.useState<ReassignmentMode | null>(null);
  const [relayTime, setRelayTime] = React.useState("");
  const [comment, setComment] = React.useState("");
  const [result, setResult] = React.useState<ReassignmentResult | null>(null);

  React.useEffect(() => {
    if (open) {
      setSelected(null);
      setMode(null);
      setRelayTime("");
      setComment("");
      setResult(null);
    }
  }, [open, missionId]);

  const missionQuery = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId!),
    enabled: open && missionId !== null,
  });
  const mission: Mission | undefined = missionQuery.data;
  const info = mission?.reassignment ?? null;
  const modes = info?.modes ?? [];
  const effectiveMode: ReassignmentMode | null = mode ?? modes[0] ?? null;

  const candidatesQuery = useQuery({
    queryKey: ["mission-eligibility", missionId],
    queryFn: () => fetchMissionEligibleInstrumentists(missionId!),
    enabled: open && missionId !== null && modes.length > 0,
    staleTime: 0,
  });

  const mutation = useMutation({
    mutationFn: () => reassignMissions([{
      missionId: missionId!,
      instrumentistId: selected!,
      mode: effectiveMode!,
      ...(effectiveMode === "RELAY" ? { relayAt: relayInstant(mission!.startAt, relayTime) } : {}),
      expectedInstrumentistId: mission?.instrumentist?.id ?? null,
    }], comment),
    onSuccess: async (res) => {
      setResult(res);
      await invalidateOperationalPlanning(queryClient, missionId);
      await queryClient.invalidateQueries({ queryKey: ["mission-audit", missionId] });
      const relayId = res.results[0]?.relayMissionId;
      if (relayId) await invalidateOperationalPlanning(queryClient, relayId);
    },
  });

  const item = result?.results[0] ?? null;
  const done = item?.status === "DONE";
  const candidates = sortedCandidates(candidatesQuery.data?.candidates ?? [], mission?.instrumentist?.id ?? null);
  const selectedCandidate = candidates.find((c) => c.id === selected) ?? null;
  const canConfirm = selected !== null && effectiveMode !== null && (effectiveMode !== "RELAY" || relayTime !== "") && !mutation.isPending && !done;

  return (
    <Dialog open={open} onClose={mutation.isPending ? undefined : onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Changer d'instrumentiste</DialogTitle>
      <DialogContent dividers>
        {missionQuery.isLoading && <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={24} /></Box>}
        {missionQuery.isError && <Alert severity="error">Impossible de charger la mission.</Alert>}

        {mission && (
          <Stack spacing={2}>
            <Box component="dl" sx={{ m: 0, display: "grid", gridTemplateColumns: "max-content 1fr", columnGap: 2, rowGap: 0.5, "& dt": { color: "text.secondary" }, "& dd": { m: 0, fontWeight: 600 } }} data-testid="change-instrumentist-summary">
              <dt>Date</dt><dd>{missionDateLabel(mission.startAt)}</dd>
              <dt>Site</dt><dd>{mission.site?.name ?? "—"}{mission.roomLabel ? ` · ${mission.roomLabel}` : ""}</dd>
              <dt>Chirurgien</dt><dd>Dr {personName(mission.surgeon)}</dd>
              <dt>Horaire prévu</dt><dd>{hhmm(mission.startAt)} – {hhmm(mission.endAt)}</dd>
              <dt>Actuellement</dt><dd>{personName(mission.instrumentist)}</dd>
            </Box>

            {modes.length === 0 && (
              <Alert severity="info" data-testid="change-instrumentist-blocked">
                {info?.blockedReason?.message ?? "Le changement d'instrumentiste n'est pas possible pour cette mission."}
              </Alert>
            )}

            {modes.length > 0 && !done && (
              <>
                {modes.length > 1 && (
                  <ToggleButtonGroup exclusive size="small" value={effectiveMode} onChange={(_e, v) => v && setMode(v)} aria-label="Type de changement">
                    <ToggleButton value="DIRECT" data-testid="mode-direct">Remplacer pour toute la mission</ToggleButton>
                    <ToggleButton value="RELAY" data-testid="mode-relay">Relais en cours de mission</ToggleButton>
                  </ToggleButtonGroup>
                )}
                {effectiveMode === "RELAY" && (
                  <Stack spacing={1}>
                    <Typography variant="body2" color="text.secondary">
                      {modes.length === 1
                        ? "Un encodage existe déjà sur cette mission : il reste rattaché à l'affectation actuelle. L'instrumentiste de remplacement reprend la suite à l'heure du relais."
                        : "L'affectation actuelle garde la partie déjà réalisée ; l'instrumentiste de remplacement reprend la suite à l'heure du relais."}
                    </Typography>
                    <TextField
                      type="time" size="small" label="Heure du relais" value={relayTime}
                      onChange={(e) => setRelayTime(e.target.value)} InputLabelProps={{ shrink: true }}
                      helperText={info?.relayWindow ? `Entre ${hhmm(info.relayWindow.from)} et ${hhmm(info.relayWindow.to)}` : undefined}
                      inputProps={{ "data-testid": "relay-time" }} sx={{ maxWidth: 220 }}
                    />
                  </Stack>
                )}

                <Stack spacing={0.75}>
                  <Typography variant="subtitle2" fontWeight={700}>Instrumentiste de remplacement</Typography>
                  {candidatesQuery.isLoading && <CircularProgress size={20} />}
                  {candidatesQuery.isError && <Alert severity="error">Impossible de charger les instrumentistes.</Alert>}
                  {candidatesQuery.data && candidates.length === 0 && (
                    <Typography variant="body2" color="text.secondary">Aucune autre instrumentiste rattachée à ce site.</Typography>
                  )}
                  <Stack spacing={0.75} sx={{ maxHeight: 260, overflowY: "auto" }} data-testid="change-instrumentist-candidates">
                    {candidates.map((c) => {
                      const isSelected = c.id === selected;
                      return (
                        <Stack
                          key={c.id} direction="row" alignItems="center" spacing={1}
                          onClick={() => c.selectable && setSelected(c.id)}
                          aria-disabled={!c.selectable} data-testid={`candidate-${c.id}`}
                          sx={{
                            p: 1, borderRadius: 1.5, border: "1px solid", cursor: c.selectable ? "pointer" : "not-allowed",
                            borderColor: isSelected ? "primary.main" : "divider", bgcolor: isSelected ? "action.selected" : "transparent",
                            opacity: c.selectable ? 1 : 0.55,
                          }}
                        >
                          <Typography variant="body2" fontWeight={600} sx={{ flex: 1 }}>{c.name}</Typography>
                          {!c.selectable && c.reasons.map((r) => (
                            <Chip key={r} label={eligibilityReasonLabel(r)} size="small" variant="outlined" sx={{ fontSize: 10, height: 18 }} />
                          ))}
                          {isSelected && <CheckIcon fontSize="small" color="primary" />}
                        </Stack>
                      );
                    })}
                  </Stack>
                </Stack>

                <TextField
                  label="Commentaire interne (facultatif)" size="small" multiline minRows={2} value={comment}
                  onChange={(e) => setComment(e.target.value)}
                  helperText="Visible uniquement dans l'historique de la mission, jamais dans les e-mails."
                  inputProps={{ maxLength: 1000, "data-testid": "reassign-comment" }}
                />

                <Alert severity="info" icon={false} data-testid="change-instrumentist-recap">
                  Seront informés automatiquement : <strong>{personName(mission.instrumentist)}</strong> (mission retirée),{" "}
                  <strong>{selectedCandidate?.name ?? "l'instrumentiste de remplacement"}</strong> (mission attribuée) et{" "}
                  <strong>Dr {personName(mission.surgeon)}</strong> (changement d'instrumentiste).
                </Alert>
              </>
            )}

            {item?.status === "FAILED" && (
              <Alert severity="error" data-testid="change-instrumentist-error">{reassignmentErrorText(item.error)}</Alert>
            )}
            {mutation.isError && <Alert severity="error">Le changement n'a pas pu être enregistré. Réessayez.</Alert>}

            {done && result && (
              <Stack spacing={1.5}>
                <Alert severity="success" data-testid="change-instrumentist-done">
                  {item?.mode === "RELAY"
                    ? `Relais enregistré : ${selectedCandidate?.name ?? "l'instrumentiste de remplacement"} reprend la mission à ${relayTime}.`
                    : `Mission désormais attribuée à ${selectedCandidate?.name ?? "l'instrumentiste de remplacement"}.`}
                </Alert>
                <ReassignmentNotificationStatus result={result} />
              </Stack>
            )}
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        {done || modes.length === 0 ? (
          <Button onClick={onClose}>Fermer</Button>
        ) : (
          <>
            <Button onClick={onClose} disabled={mutation.isPending}>Annuler</Button>
            <Button variant="contained" disableElevation disabled={!canConfirm} onClick={() => { setResult(null); mutation.mutate(); }} data-testid="confirm-reassignment">
              {mutation.isPending ? "Enregistrement…" : "Confirmer la réaffectation"}
            </Button>
          </>
        )}
      </DialogActions>
    </Dialog>
  );
}
