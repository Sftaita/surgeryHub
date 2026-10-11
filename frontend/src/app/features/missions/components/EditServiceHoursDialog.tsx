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

/** endUnknown (D-143) : journée en cours — seul le début est enregistré, en brouillon. */
type HoursDraft = { start: number; end: number; pause: number; nextDay: boolean; endUnknown: boolean };

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
  if (!start || !end) return { start: 450, end: 930, pause: 0, nextDay: false, endUnknown: false };
  return {
    start: toStepperMinutes(start),
    end: toStepperMinutes(end),
    pause: 0,
    nextDay: !end.isSame(start, "day"),
    endUnknown: false,
  };
}

/**
 * Valeur initiale à l'ouverture — toujours la valeur ACTUELLEMENT enregistrée quand il y
 * en a une, l'horaire prévu seulement comme point de départ des steppers d'une première
 * saisie (jamais enregistré tant que l'instrumentiste ne confirme pas, D-143) :
 * - début ET fin réels connus → repris tels quels, pause = pause enregistrée ;
 * - début seul (brouillon de la journée en cours) → repris, « fin pas encore connue » cochée ;
 * - durée seule (saisie antérieure à D-143) → début prévu, fin = début + durée : à
 *   confirmer, car début et fin sont désormais obligatoires pour soumettre ;
 * - rien d'enregistré → horaire prévu.
 */
function initialDraft(mission: Mission, execution: MissionExecutionInfo | undefined): HoursDraft {
  if (execution?.hasExecutionRecord && execution.actualStartAt && execution.actualEndAt) {
    const start = dayjs(execution.actualStartAt);
    const end = dayjs(execution.actualEndAt);
    const span = Math.max(0, end.diff(start, "minute"));
    const recorded = execution.actualDurationMinutes ?? span;
    return {
      start: start.hour() * 60 + start.minute(),
      end: end.hour() * 60 + end.minute(),
      pause: execution.breakMinutes ?? Math.max(0, span - recorded),
      nextDay: !end.isSame(start, "day"),
      endUnknown: false,
    };
  }
  if (execution?.hasExecutionRecord && execution.actualStartAt) {
    const start = dayjs(execution.actualStartAt);
    return { ...defaultDraft(mission), start: start.hour() * 60 + start.minute(), pause: execution.breakMinutes ?? 0, endUnknown: true };
  }
  if (execution?.hasExecutionRecord && execution.actualDurationMinutes != null) {
    const planned = defaultDraft(mission);
    const endAbs = planned.start + execution.actualDurationMinutes;
    return { start: planned.start, end: endAbs % 1440, pause: 0, nextDay: endAbs >= 1440, endUnknown: false };
  }
  return defaultDraft(mission);
}

/**
 * D-143 — instants réels envoyés au serveur (ISO 8601 avec décalage local), construits sur
 * le JOUR de la mission : début = jour + minutes, fin = jour (+1 si lendemain) + minutes.
 */
function toActualBody(mission: Mission, draft: HoursDraft): { actualStartAt: string; actualEndAt?: string; breakMinutes: number } {
  const day = dayjs(mission.startAt).startOf("day");
  const body: { actualStartAt: string; actualEndAt?: string; breakMinutes: number } = {
    actualStartAt: day.add(draft.start, "minute").format(),
    breakMinutes: draft.pause,
  };
  if (!draft.endUnknown) {
    body.actualEndAt = day.add(draft.end + (draft.nextDay ? 1440 : 0), "minute").format();
  }
  return body;
}

/**
 * docs/design/screens/heures-prestees/README.md — sheet "Heures prestées" : steppers
 * ±15 min Début/Fin/Pause + case "se termine le lendemain", jamais d'input horaire
 * clavier. À la réouverture, la modale repart de la valeur enregistrée (initialDraft()),
 * l'horaire prévu ne servant qu'à une première saisie.
 */
export default function EditServiceHoursDialog({ open, onClose, mission, onSaved, helpTopicId }: Props) {
  const queryClient = useQueryClient();
  const toast = useToast();

  // Même cache que les pages qui affichent les heures (voir executionKey plus bas) — lu à
  // chaque ouverture, donc toujours la dernière valeur sauvegardée (ou optimiste).
  const readExecution = () => queryClient.getQueryData<MissionExecutionInfo>(["mission-execution", mission.id]);
  const [draft, setDraft] = React.useState<HoursDraft>(() => initialDraft(mission, readExecution()));

  React.useEffect(() => {
    if (open) setDraft(initialDraft(mission, readExecution()));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, mission.id]);

  const endEff = draft.end + (draft.nextDay ? 1440 : 0);
  const totalMinutes = Math.max(0, endEff - draft.start - draft.pause);
  const maxPause = draft.endUnknown ? 600 : Math.max(0, endEff - draft.start - STEP);
  const isFirstEntry = !readExecution()?.hasExecutionRecord;

  // Source de vérité unique des heures prestées : ["mission-execution", missionId], lu
  // par GET /api/missions/{id}/execution (MissionExecutionController::toDto()) — jamais
  // ["mission", missionId].service, un champ que MissionDetailDto n'expose plus depuis le
  // renommage InstrumentistService -> MissionExecution (D-071). Toute page qui affiche les
  // heures prestées (encodage instrumentiste, fiche mission instrumentiste, fiche mission
  // manager) doit lire ce même cache pour rester synchronisée avec cette mutation.
  const executionKey = React.useMemo(() => ["mission-execution", mission.id] as const, [mission.id]);

  const mutation = useMutation({
    mutationFn: (body: { actualStartAt: string; actualEndAt?: string; breakMinutes: number; hoursSource: string }) =>
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
      const complete = body.actualEndAt !== undefined;
      const optimistic: MissionExecutionInfo = {
        missionId: mission.id,
        hasExecutionRecord: true,
        actualStartAt: body.actualStartAt,
        actualEndAt: body.actualEndAt ?? previous?.actualEndAt ?? null,
        actualDurationMinutes: complete ? totalMinutes : previous?.actualDurationMinutes ?? null,
        breakMinutes: body.breakMinutes,
        hoursSource: body.hoursSource,
        effectiveDurationMinutes: complete ? totalMinutes : previous?.effectiveDurationMinutes ?? 0,
        effectiveDurationSource: complete ? "ACTUAL_TIMES" : previous?.effectiveDurationSource ?? "PLANNED",
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
      toast.success(updated.actualEndAt ? "Heures prestées enregistrées." : "Heure de début enregistrée — complétez la fin quand votre journée sera terminée.");
      onSaved?.();
    },
    onError: (err: any, _body, ctx) => {
      if (ctx?.previous !== undefined) queryClient.setQueryData(executionKey, ctx.previous);
      toast.error(extractErrorMessage(err));
    },
  });

  function handleSave() {
    // D-143 — début, fin et pause réels ; la durée est dérivée par le serveur (fin − début −
    // pause), jamais envoyée séparément. « Fin pas encore connue » n'envoie que le début.
    mutation.mutate({ ...toActualBody(mission, draft), hoursSource: "INSTRUMENTIST" });
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
          onPlus={() => setDraft((d) => ({ ...d, start: Math.min(d.nextDay || d.endUnknown ? 1440 - STEP : d.end - STEP, d.start + STEP) }))}
          minusDisabled={mutation.isPending || draft.start <= 0}
          plusDisabled={mutation.isPending || draft.start >= (draft.nextDay || draft.endUnknown ? 1440 - STEP : draft.end - STEP)}
          minusAriaLabel="Moins 15 minutes"
          plusAriaLabel="Plus 15 minutes"
        />
        <CheckboxRow
          checked={draft.endUnknown}
          onChange={() => setDraft((d) => (d.endUnknown && !d.nextDay && d.end <= d.start ? { ...d, endUnknown: false, end: Math.min(1440 - STEP, d.start + STEP) } : { ...d, endUnknown: !d.endUnknown }))}
          ariaLabel="Fin pas encore connue"
          indent={86}
          label={
            <>
              Fin pas encore connue{" "}
              <Box component="span" sx={{ color: GRAY_400, fontWeight: 400 }}>(journée en cours)</Box>
            </>
          }
        />

        {!draft.endUnknown && (<>
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
        </>)}

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
        <Box sx={{ fontSize: 22, fontWeight: 800, color: GREEN_800, fontVariantNumeric: "tabular-nums" }}>{draft.endUnknown ? "—" : fmtDuration(totalMinutes)}</Box>
      </Box>

      {(isFirstEntry || draft.endUnknown) && (
        <Box data-testid="hours-hint" sx={{ mt: "10px", fontSize: 12.5, color: GRAY_500 }}>
          {draft.endUnknown
            ? "Votre heure de début sera enregistrée dans votre brouillon. Revenez indiquer la fin : elle est nécessaire pour terminer l'encodage."
            : "Les valeurs proposées sont celles du planning : ajustez-les à vos heures réellement prestées avant d'enregistrer."}
        </Box>
      )}

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
