import * as React from "react";
import { Box, Stack } from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";

import { SheetModal } from "../../../ui/sheet/SheetModal";
import { StepperRow } from "../../../ui/sheet/StepperRow";
import { CheckboxRow } from "../../../ui/sheet/Checkbox";
import { useToast } from "../../../ui/toast/useToast";
import { invalidateOperationalPlanning } from "../../missions/dispatch/missionDispatch.api";
import {
  formatMinutes,
  prestationError,
  savePrestationPresence,
  type PrestationView,
} from "../api/prestations.api";

const GREEN_50 = "#EFFAF5";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_400 = "#98A2AE";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_800 = "#243240";
const AMBER_50 = "#FFF7E6";
const AMBER_800 = "#8A5A00";

const STEP = 15; // même pas que « Heures prestées » (docs/design/screens/heures-prestees)

type Draft = { start: number; end: number; nextDay: boolean; endUnknown: boolean };

function toStep(d: dayjs.Dayjs): number {
  return Math.min(1440 - STEP, Math.round((d.hour() * 60 + d.minute()) / STEP) * STEP);
}
function fmtTime(m: number): string {
  return `${String(Math.floor(m / 60)).padStart(2, "0")}h${String(m % 60).padStart(2, "0")}`;
}

/**
 * Valeur à l'ouverture : la présence commune enregistrée s'il y en a une, sinon l'union
 * planifiée de la prestation comme simple point de départ des steppers (jamais enregistrée
 * sans confirmation, D-143).
 */
function initialDraft(p: PrestationView): Draft {
  if (p.presence?.actualStartAt) {
    const s = dayjs(p.presence.actualStartAt);
    if (p.presence.actualEndAt) {
      const e = dayjs(p.presence.actualEndAt);
      return { start: s.hour() * 60 + s.minute(), end: e.hour() * 60 + e.minute(), nextDay: !e.isSame(s, "day"), endUnknown: false };
    }
    const plannedEnd = dayjs(p.planned.endAt);
    return { start: s.hour() * 60 + s.minute(), end: toStep(plannedEnd), nextDay: false, endUnknown: true };
  }
  const s = dayjs(p.planned.startAt);
  const e = dayjs(p.planned.endAt);
  return { start: toStep(s), end: toStep(e), nextDay: !e.isSame(s, "day"), endUnknown: false };
}

type Props = {
  open: boolean;
  onClose: () => void;
  /** Mission depuis laquelle la prestation est adressée (n'importe laquelle du groupe). */
  anchorMissionId: number;
  prestation: PrestationView;
  onSaved?: () => void;
  helpTopicId?: string;
};

/**
 * D-156 — « Heures de la prestation » : une seule saisie de la présence réelle pour toutes
 * les missions simultanées. Le serveur ventile ensuite (part imputée par mission) ; aucune
 * ventilation n'est calculée ici.
 */
export default function PrestationHoursDialog({ open, onClose, anchorMissionId, prestation, onSaved, helpTopicId }: Props) {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [draft, setDraft] = React.useState<Draft>(() => initialDraft(prestation));
  const [confirmReplace, setConfirmReplace] = React.useState(false);
  const [needsReplaceConfirmation, setNeedsReplaceConfirmation] = React.useState(false);

  React.useEffect(() => {
    if (open) {
      setDraft(initialDraft(prestation));
      setConfirmReplace(false);
      setNeedsReplaceConfirmation(prestation.ownHoursMissionIds.length > 0);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, prestation.key]);

  const endEff = draft.end + (draft.nextDay ? 1440 : 0);
  const total = Math.max(0, endEff - draft.start);

  const mutation = useMutation({
    mutationFn: () => {
      const day = dayjs(prestation.date).startOf("day");
      return savePrestationPresence(anchorMissionId, {
        actualStartAt: day.add(draft.start, "minute").format(),
        ...(draft.endUnknown ? {} : { actualEndAt: day.add(endEff, "minute").format() }),
        expectedMissionIds: prestation.missionIds,
        ...(prestation.presence ? { expectedRevision: prestation.presence.revision } : {}),
        ...(confirmReplace ? { convertOwnHours: true } : {}),
      });
    },
    onSuccess: (view) => {
      queryClient.setQueryData(["prestation", anchorMissionId], view);
      prestation.missionIds.forEach((id) => queryClient.invalidateQueries({ queryKey: ["mission-execution", id] }));
      void invalidateOperationalPlanning(queryClient);
      toast.success(draft.endUnknown ? "Heure de début enregistrée pour la prestation." : "Heures de la prestation enregistrées.");
      onSaved?.();
      onClose();
    },
    onError: (err) => {
      const e = prestationError(err);
      if (e.code === "PRESTATION_HAS_OWN_HOURS") {
        setNeedsReplaceConfirmation(true);
      }
      if (e.code === "PRESTATION_CHANGED" || e.code === "PRESENCE_REVISION_CHANGED") {
        void queryClient.invalidateQueries({ queryKey: ["prestation"] });
      }
      toast.error(e.message);
    },
  });

  const blocked = needsReplaceConfirmation && !confirmReplace;

  return (
    <SheetModal open={open} title="Heures de la prestation" onClose={onClose} closeDisabled={mutation.isPending} helpTopicId={helpTopicId}>
      <Box sx={{ background: "#F5F7FA", borderRadius: "12px", padding: "12px 14px", mt: "14px", fontSize: 13.5, color: GRAY_600 }}>
        <Box>
          {prestation.size} missions simultanées · prévu{" "}
          <Box component="span" sx={{ fontWeight: 700, color: GRAY_800, fontVariantNumeric: "tabular-nums" }}>
            {dayjs(prestation.planned.startAt).format("HH[h]mm")} → {dayjs(prestation.planned.endAt).format("HH[h]mm")}
          </Box>{" "}
          ({formatMinutes(prestation.planned.unionMinutes)})
        </Box>
        <Box sx={{ mt: "6px", fontSize: 12.5, color: GRAY_500 }}>
          Saisissez une seule fois votre présence réelle : elle est répartie automatiquement entre les missions.
        </Box>
      </Box>

      <Stack spacing="14px" sx={{ mt: "18px" }}>
        <StepperRow
          label="Début"
          value={fmtTime(draft.start)}
          onMinus={() => setDraft((d) => ({ ...d, start: Math.max(0, d.start - STEP) }))}
          onPlus={() => setDraft((d) => ({ ...d, start: Math.min(d.nextDay || d.endUnknown ? 1440 - STEP : d.end - STEP, d.start + STEP) }))}
          minusDisabled={mutation.isPending || draft.start <= 0}
          plusDisabled={mutation.isPending || draft.start >= (draft.nextDay || draft.endUnknown ? 1440 - STEP : draft.end - STEP)}
          minusAriaLabel="Moins 15 minutes"
          plusAriaLabel="Plus 15 minutes"
        />
        <CheckboxRow
          checked={draft.endUnknown}
          onChange={() => setDraft((d) => ({ ...d, endUnknown: !d.endUnknown }))}
          ariaLabel="Fin pas encore connue"
          indent={86}
          label={<>Fin pas encore connue <Box component="span" sx={{ color: GRAY_400, fontWeight: 400 }}>(journée en cours)</Box></>}
        />
        {!draft.endUnknown && (
          <>
            <StepperRow
              label="Fin"
              value={fmtTime(draft.end) + (draft.nextDay ? " (+1j)" : "")}
              onMinus={() => setDraft((d) => ({ ...d, end: Math.max(d.nextDay ? 0 : d.start + STEP, d.end - STEP) }))}
              onPlus={() => setDraft((d) => ({ ...d, end: Math.min(1440 - STEP, d.end + STEP) }))}
              minusDisabled={mutation.isPending || draft.end <= (draft.nextDay ? 0 : draft.start + STEP)}
              plusDisabled={mutation.isPending || draft.end >= 1440 - STEP}
              minusAriaLabel="Moins 15 minutes"
              plusAriaLabel="Plus 15 minutes"
            />
            <CheckboxRow
              checked={draft.nextDay}
              onChange={() => setDraft((d) => ({ ...d, nextDay: !d.nextDay }))}
              ariaLabel="Se termine le lendemain"
              indent={86}
              label={<>Se termine le lendemain <Box component="span" sx={{ color: GRAY_400, fontWeight: 400 }}>(après minuit)</Box></>}
            />
          </>
        )}
      </Stack>

      <Box sx={{ display: "flex", alignItems: "center", justifyContent: "space-between", background: GREEN_50, borderRadius: "13px", padding: "14px 16px", mt: "18px" }}>
        <Box sx={{ fontSize: 14, fontWeight: 700, color: GREEN_800 }}>Présence réelle</Box>
        <Box sx={{ fontSize: 22, fontWeight: 800, color: GREEN_800, fontVariantNumeric: "tabular-nums" }}>{draft.endUnknown ? "—" : formatMinutes(total)}</Box>
      </Box>

      {needsReplaceConfirmation && (
        <Box data-testid="prestation-own-hours" sx={{ mt: "12px", background: AMBER_50, borderRadius: "12px", padding: "12px 14px", fontSize: 13, color: AMBER_800 }}>
          Des heures ont déjà été saisies séparément sur une des missions. Elles seront remplacées par ces heures communes
          (les anciennes valeurs restent dans l'historique).
          <CheckboxRow
            checked={confirmReplace}
            onChange={() => setConfirmReplace((v) => !v)}
            ariaLabel="Remplacer les heures déjà saisies"
            label="Remplacer les heures déjà saisies"
          />
        </Box>
      )}

      <Box
        component="button"
        type="button"
        onClick={() => mutation.mutate()}
        disabled={mutation.isPending || blocked}
        sx={{
          mt: "16px", width: "100%", height: 52, border: "none", borderRadius: "12px",
          background: GREEN_700, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
          cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
          "&:hover": { background: GREEN_800 },
          "&:disabled": { opacity: 0.6, cursor: "default", boxShadow: "none" },
        }}
      >
        {mutation.isPending ? "…" : "Enregistrer les heures"}
      </Box>
      <Box
        component="button"
        type="button"
        onClick={onClose}
        disabled={mutation.isPending}
        sx={{ mt: "8px", width: "100%", height: 44, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
      >
        Annuler
      </Box>
    </SheetModal>
  );
}
