import * as React from "react";
import { Box, Stack } from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import dayjs from "dayjs";

import type { Mission } from "../api/missions.types";
import { updateMissionExecution, type MissionExecutionInfo } from "../api/missions.api";
import { useToast } from "../../../ui/toast/useToast";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import { StepperRow } from "../../../ui/sheet/StepperRow";
import { CheckboxRow } from "../../../ui/sheet/Checkbox";

const GREEN_50 = "#EFFAF5";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_400 = "#98A2AE";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_800 = "#243240";

const STEP = 15; // minutes — docs/design/screens/heures-prestees (pas ±15 min, pas de saisie clavier)

type Props = {
  open: boolean;
  onClose: () => void;
  mission: Mission;
  /** Appelé après une sauvegarde réussie — pilote l'horodatage "Enregistré à" du header. */
  onSaved?: () => void;
  /** Topic d'aide contextuel — omis par défaut, ce composant est réutilisé hors encodage. */
  helpTopicId?: string;
};

type HoursDraft = { start: number; end: number; pause: number; nextDay: boolean };

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

function fmtTime(minutes: number): string {
  const h = String(Math.floor(minutes / 60)).padStart(2, "0");
  const m = String(minutes % 60).padStart(2, "0");
  return `${h}h${m}`;
}
function fmtDuration(minutes: number): string {
  return `${Math.floor(minutes / 60)}h${String(minutes % 60).padStart(2, "0")}`;
}

/** Arrondit une heure planifiée au 1/4h le plus proche pour l'aligner sur les steppers. */
function toStepperMinutes(d: dayjs.Dayjs): number {
  const raw = d.hour() * 60 + d.minute();
  return Math.min(1440 - STEP, Math.round(raw / STEP) * STEP);
}

function defaultDraft(mission: Mission): HoursDraft {
  const start = mission.startAt ? dayjs(mission.startAt) : null;
  const end = mission.endAt ? dayjs(mission.endAt) : null;
  if (!start || !end) return { start: 450, end: 930, pause: 0, nextDay: false };
  return {
    start: toStepperMinutes(start),
    end: toStepperMinutes(end),
    pause: 0,
    nextDay: !end.isSame(start, "day"),
  };
}

/**
 * docs/design/screens/heures-prestees/README.md — sheet "Heures prestées" : steppers
 * ±15 min Début/Fin/Pause + case "se termine le lendemain", jamais d'input horaire
 * clavier. Le backend ne stocke qu'une durée décimale (InstrumentistService.hours,
 * pas de start/end) : à la réouverture on repart donc toujours de l'horaire prévu de
 * la mission, jamais d'une valeur déjà enregistrée (impossible à reconstruire).
 */
export default function EditServiceHoursDialog({ open, onClose, mission, onSaved, helpTopicId }: Props) {
  const queryClient = useQueryClient();
  const toast = useToast();

  const [draft, setDraft] = React.useState<HoursDraft>(() => defaultDraft(mission));

  React.useEffect(() => {
    if (open) setDraft(defaultDraft(mission));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, mission.id]);

  const endEff = draft.end + (draft.nextDay ? 1440 : 0);
  const totalMinutes = Math.max(0, endEff - draft.start - draft.pause);
  const maxPause = Math.max(0, endEff - draft.start - STEP);

  // Source de vérité unique des heures prestées : ["mission-execution", missionId], lu
  // par GET /api/missions/{id}/execution (MissionExecutionController::toDto()) — jamais
  // ["mission", missionId].service, un champ que MissionDetailDto n'expose plus depuis le
  // renommage InstrumentistService -> MissionExecution (D-071). Toute page qui affiche les
  // heures prestées (encodage instrumentiste, fiche mission instrumentiste, fiche mission
  // manager) doit lire ce même cache pour rester synchronisée avec cette mutation.
  const executionKey = React.useMemo(() => ["mission-execution", mission.id] as const, [mission.id]);

  const mutation = useMutation({
    mutationFn: (body: { actualDurationMinutes: number; hoursSource: string }) =>
      updateMissionExecution(mission.id, body),
    onMutate: async (body) => {
      await queryClient.cancelQueries({ queryKey: executionKey });
      const previous = queryClient.getQueryData<MissionExecutionInfo>(executionKey);
      // Affichage optimiste : la modale se ferme immédiatement, la nouvelle valeur est
      // visible sans attendre la réponse serveur — rollback vers `previous` si l'appel
      // échoue. Construit un MissionExecutionInfo complet même si le cache était encore
      // vide (première saisie sur cette mission, jamais consultée depuis ce point d'entrée) :
      // un merge partiel sur `undefined` laisserait `hasExecutionRecord` absent et la carte
      // retomberait sur "Non renseigné" malgré la saisie.
      const optimistic: MissionExecutionInfo = {
        missionId: mission.id,
        hasExecutionRecord: true,
        actualStartAt: previous?.actualStartAt ?? null,
        actualEndAt: previous?.actualEndAt ?? null,
        actualDurationMinutes: body.actualDurationMinutes,
        hoursSource: body.hoursSource,
        effectiveDurationMinutes: body.actualDurationMinutes,
        effectiveDurationSource: "ACTUAL_EXPLICIT",
        disputes: previous?.disputes ?? [],
      };
      queryClient.setQueryData<MissionExecutionInfo>(executionKey, optimistic);
      onClose();
      return { previous };
    },
    onSuccess: (updated) => {
      // Le serveur reste la source de vérité finale : PATCH .../execution renvoie
      // exactement la même forme (MissionExecutionDto) que GET .../execution — remplacement
      // complet et fiable, jamais un patch partiel qui devinerait des champs absents.
      queryClient.setQueryData<MissionExecutionInfo>(executionKey, updated);
      queryClient.invalidateQueries({ queryKey: executionKey });
      toast.success("Heures prestées enregistrées.");
      onSaved?.();
    },
    onError: (err: any, _body, ctx) => {
      if (ctx?.previous !== undefined) queryClient.setQueryData(executionKey, ctx.previous);
      toast.error(extractErrorMessage(err));
    },
  });

  function handleSave() {
    // totalMinutes est déjà un multiple de 15 (steppers) — plus besoin de le reconvertir
    // en heures décimales, actualDurationMinutes attend directement des minutes entières.
    mutation.mutate({ actualDurationMinutes: totalMinutes, hoursSource: "INSTRUMENTIST" });
  }

  const plannedLabel =
    mission.startAt && mission.endAt
      ? `${dayjs(mission.startAt).format("HH[h]mm")} → ${dayjs(mission.endAt).format("HH[h]mm")}`
      : "—";

  return (
    <SheetModal open={open} title="Heures prestées" onClose={onClose} closeDisabled={mutation.isPending} helpTopicId={helpTopicId}>
      <Box sx={{ display: "flex", alignItems: "center", gap: "10px", background: "#F5F7FA", borderRadius: "12px", padding: "12px 14px", mt: "14px" }}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={GRAY_500} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0 }}>
          <rect x="3" y="4" width="18" height="17" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" />
        </svg>
        <Box sx={{ fontSize: 13.5, color: GRAY_600 }}>
          Horaire prévu : <Box component="span" sx={{ fontWeight: 700, color: GRAY_800, fontVariantNumeric: "tabular-nums" }}>{plannedLabel}</Box>
        </Box>
      </Box>

      <Stack spacing="14px" sx={{ mt: "18px" }}>
        <StepperRow
          label="Début"
          value={fmtTime(draft.start)}
          onMinus={() => setDraft((d) => ({ ...d, start: Math.max(0, d.start - STEP) }))}
          onPlus={() => setDraft((d) => ({ ...d, start: Math.min(d.nextDay ? 1440 - STEP : d.end - STEP, d.start + STEP) }))}
          minusDisabled={mutation.isPending || draft.start <= 0}
          plusDisabled={mutation.isPending || draft.start >= (draft.nextDay ? 1440 - STEP : draft.end - STEP)}
          minusAriaLabel="Moins 15 minutes"
          plusAriaLabel="Plus 15 minutes"
        />
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
          label={
            <>
              Se termine le lendemain{" "}
              <Box component="span" sx={{ color: GRAY_400, fontWeight: 400 }}>(après minuit)</Box>
            </>
          }
        />

        <StepperRow
          label="Pause"
          value={`${draft.pause} min`}
          onMinus={() => setDraft((d) => ({ ...d, pause: Math.max(0, d.pause - STEP) }))}
          onPlus={() => setDraft((d) => ({ ...d, pause: Math.min(maxPause, d.pause + STEP) }))}
          minusDisabled={mutation.isPending || draft.pause <= 0}
          plusDisabled={mutation.isPending || draft.pause >= maxPause}
          minusAriaLabel="Moins 15 minutes"
          plusAriaLabel="Plus 15 minutes"
        />
      </Stack>

      <Box sx={{ display: "flex", alignItems: "center", justifyContent: "space-between", background: GREEN_50, borderRadius: "13px", padding: "14px 16px", mt: "18px" }}>
        <Box sx={{ fontSize: 14, fontWeight: 700, color: GREEN_800 }}>Total presté</Box>
        <Box sx={{ fontSize: 22, fontWeight: 800, color: GREEN_800, fontVariantNumeric: "tabular-nums" }}>{fmtDuration(totalMinutes)}</Box>
      </Box>

      <Box
        component="button"
        type="button"
        onClick={handleSave}
        disabled={mutation.isPending}
        sx={{
          mt: "16px", width: "100%", height: 52, border: "none", borderRadius: "12px",
          background: GREEN_700, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
          cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
          "&:hover": { background: GREEN_800 }, "&:active": { transform: "translateY(0.5px)" },
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
