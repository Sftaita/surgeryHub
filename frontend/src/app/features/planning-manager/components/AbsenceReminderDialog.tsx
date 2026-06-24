import * as React from "react";
import {
  Box, Button, Checkbox, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle,
  Stack, TextField, Typography,
} from "@mui/material";
import { useMutation, useQuery } from "@tanstack/react-query";

import {
  getMissingAbsencesPreview, getEncodedAbsencesPreview,
  requestMissingAbsences, confirmEncodedAbsences,
  type AbsenceReminderPerson, type EncodedAbsenceGroup,
} from "../api/planning.api";
import { useToast } from "../../../ui/toast/useToast";
import { Avatar } from "../../../ui/avatar/Avatar";

const ROLE_LABELS: Record<string, string> = { INSTRUMENTIST: "Instrumentiste", SURGEON: "Chirurgien" };

// No "Bonjour," here — each individual email gets its own personalized greeting, rendered
// by the backend template ("Bonjour Dr {nom}" for surgeons, "Bonjour {prénom}" for
// instrumentists), never duplicated with this editable body text.
const DEFAULT_MESSAGES: Record<Mode, string> = {
  missing: `Nous n'avons actuellement aucun congé ou indisponibilité encodé pour vous pour les trois prochains mois.

Pourriez-vous nous transmettre vos éventuels congés ou indisponibilités prévus en répondant à boost.conge@gmail.com ?

À terme, cette demande se fera directement via l'application SurgicalHub. Vous serez tenu(e) au courant dès que cette fonctionnalité sera disponible.

Merci d'avance.`,
  encoded: `Voici le récapitulatif des jours de congé ou d'indisponibilité actuellement encodés pour vous.

À terme, cette confirmation se fera directement via l'application SurgicalHub. Vous serez tenu(e) au courant dès que cette fonctionnalité sera disponible.

Merci.`,
};

type Mode = "missing" | "encoded";

interface Props {
  mode: Mode;
  open: boolean;
  onClose: () => void;
}

function formatFr(iso: string): string {
  return new Date(iso + "T00:00:00").toLocaleDateString("fr-FR");
}

/**
 * Shared dialog for the two manager "congés" reminder actions. Order: message first, then
 * the person list (each with a checkbox, checked by default), then Envoyer — per explicit
 * request, the message is the first thing a manager sees/edits, not buried under a long list.
 *
 * "missing" sends ONE email to a fixed mailbox listing the selected people.
 * "encoded" sends ONE INDIVIDUAL email PER selected person, to their own address, containing
 * only their own dates — never a single grouped email (see D-051 amendment).
 *
 * The preview is always fetched from the backend (never recomputed client-side) so the list
 * shown here is guaranteed identical to what the send endpoint would consider — see D-051.
 */
export function AbsenceReminderDialog({ mode, open, onClose }: Props) {
  const toast = useToast();
  const [message, setMessage] = React.useState(DEFAULT_MESSAGES[mode]);
  const [selectedIds, setSelectedIds] = React.useState<Set<number>>(new Set());

  const missingPreview = useQuery({
    queryKey: ["absences", "missing-preview"],
    queryFn: getMissingAbsencesPreview,
    enabled: open && mode === "missing",
  });

  const encodedPreview = useQuery({
    queryKey: ["absences", "encoded-preview"],
    queryFn: getEncodedAbsencesPreview,
    enabled: open && mode === "encoded",
  });

  const isLoading = mode === "missing" ? missingPreview.isLoading : encodedPreview.isLoading;
  const allIds = React.useMemo(() => mode === "missing"
    ? (missingPreview.data?.people ?? []).map((p) => p.id)
    : (encodedPreview.data?.groups ?? []).map((g) => g.user.id),
    [mode, missingPreview.data, encodedPreview.data]);

  // Reset message whenever the dialog opens or the mode changes.
  React.useEffect(() => {
    if (open) setMessage(DEFAULT_MESSAGES[mode]);
  }, [open, mode]);

  // Initialize the selection (all checked) exactly once per "open session" — when the dialog
  // transitions from closed to open, or its mode changes while open. A background refetch of
  // the preview (e.g. cross-query invalidation) must NEVER silently re-check everyone and
  // wipe out a manager's manual exclusions — `initializedForRef` tracks which open-session
  // (keyed by mode) has already been initialized, independently of how many times `allIds`
  // changes afterwards.
  const initializedForRef = React.useRef<Mode | null>(null);

  React.useEffect(() => {
    if (!open) {
      initializedForRef.current = null;
    }
  }, [open]);

  React.useEffect(() => {
    if (open && !isLoading && initializedForRef.current !== mode) {
      setSelectedIds(new Set(allIds));
      initializedForRef.current = mode;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, isLoading, mode, allIds.join(",")]);

  function toggle(id: number) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  const sendMutation = useMutation({
    mutationFn: () => mode === "missing"
      ? requestMissingAbsences(message, [...selectedIds])
      : confirmEncodedAbsences(message, [...selectedIds]),
    onSuccess: (result) => {
      toast.success(`${result.count} email${result.count > 1 ? "s" : ""} individuel${result.count > 1 ? "s" : ""} envoyé${result.count > 1 ? "s" : ""}`);
      onClose();
    },
    onError: (err: any) => toast.error(err?.response?.data?.error?.message ?? err?.message ?? String(err)),
  });

  // Synchronous double-submit guard — `sendMutation.isPending` only flips after a re-render,
  // which is NOT fast enough to stop a real double-click or several rapid clicks fired in the
  // same tick (confirmed empirically: 3 rapid clicks dispatched 3 real API calls before the
  // `disabled` prop caught up). `sendingRef` blocks re-entrancy immediately, synchronously,
  // regardless of render timing, and is only ever cleared in onSettled (success or error).
  const sendingRef = React.useRef(false);

  function handleSend() {
    if (sendingRef.current) return;
    sendingRef.current = true;
    sendMutation.mutate(undefined, {
      onSettled: () => { sendingRef.current = false; },
    });
  }

  const selectedCount = selectedIds.size;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle fontWeight={700}>
        {mode === "missing" ? "Demander les congés" : "Confirmer les congés encodés"}
      </DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <TextField
            label="Message personnalisé"
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            multiline minRows={5} fullWidth size="small"
          />

          {isLoading ? (
            <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={24} /></Box>
          ) : (
            <>
              <Typography variant="body2" color="text.secondary">
                {mode === "missing"
                  ? `${selectedCount} personne${selectedCount > 1 ? "s" : ""} sélectionnée${selectedCount > 1 ? "s" : ""} (sans absence renseignée pour les 3 prochains mois) — recevront chacune un email individuel à sa propre adresse.`
                  : `${selectedCount} personne${selectedCount > 1 ? "s" : ""} sélectionnée${selectedCount > 1 ? "s" : ""} (au moins un congé futur) — recevront chacune un email individuel avec tous leurs congés futurs.`}
              </Typography>

              {mode === "missing" ? (
                <Stack spacing={0.75} sx={{ maxHeight: 220, overflowY: "auto" }}>
                  {(missingPreview.data?.people ?? []).map((p: AbsenceReminderPerson) => (
                    <Stack key={p.id} direction="row" spacing={1} alignItems="center">
                      <Checkbox size="small" checked={selectedIds.has(p.id)} onChange={() => toggle(p.id)} />
                      <Avatar name={p.name} size={26} />
                      <Box sx={{ minWidth: 0 }}>
                        <Typography sx={{ fontSize: 13, fontWeight: 600 }} noWrap>
                          {p.name} <Typography component="span" sx={{ fontSize: 12, color: "text.secondary", fontWeight: 500 }}>({ROLE_LABELS[p.role]})</Typography>
                        </Typography>
                        <Typography sx={{ fontSize: 11.5, color: "text.secondary" }} noWrap>{p.email}</Typography>
                      </Box>
                    </Stack>
                  ))}
                </Stack>
              ) : (
                <Stack spacing={1} sx={{ maxHeight: 260, overflowY: "auto" }}>
                  {(encodedPreview.data?.groups ?? []).map((g: EncodedAbsenceGroup) => (
                    <Stack key={g.user.id} direction="row" spacing={1} alignItems="flex-start" sx={{ p: 1.25, border: "1px solid", borderColor: "divider", borderRadius: 1.5 }}>
                      <Checkbox size="small" checked={selectedIds.has(g.user.id)} onChange={() => toggle(g.user.id)} sx={{ mt: -0.5 }} />
                      <Box sx={{ minWidth: 0 }}>
                        <Stack direction="row" spacing={1.25} alignItems="center" sx={{ mb: 0.5 }}>
                          <Avatar name={g.user.name} size={24} />
                          <Typography sx={{ fontSize: 13, fontWeight: 600 }}>
                            {g.user.name} <Typography component="span" sx={{ fontSize: 11.5, color: "text.secondary", fontWeight: 500 }}>({ROLE_LABELS[g.user.role]})</Typography>
                          </Typography>
                        </Stack>
                        <Typography sx={{ fontSize: 12, color: "text.secondary" }}>
                          {g.absences.map((a) => a.dateStart === a.dateEnd ? formatFr(a.dateStart) : `${formatFr(a.dateStart)} → ${formatFr(a.dateEnd)}`).join(" · ")}
                        </Typography>
                      </Box>
                    </Stack>
                  ))}
                </Stack>
              )}
            </>
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} color="inherit">Annuler</Button>
        <Button
          variant="contained" disableElevation
          onClick={handleSend}
          disabled={isLoading || sendMutation.isPending || selectedCount === 0}
        >
          {sendMutation.isPending ? <CircularProgress size={16} /> : "Envoyer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
