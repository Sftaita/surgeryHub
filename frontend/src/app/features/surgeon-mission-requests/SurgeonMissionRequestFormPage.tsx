import * as React from "react";
import { useNavigate } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Box, Stack } from "@mui/material";
import dayjs from "dayjs";

import type { MissionType } from "../missions/api/missions.types";
import { fetchMe } from "../me/api/me.api";
import { createMyMissionRequest } from "./api/surgeonMissionRequests.api";

import { useToast } from "../../ui/toast/useToast";
import { StepperRow } from "../../ui/sheet/StepperRow";
import { CheckboxRow } from "../../ui/sheet/Checkbox";
import { SelectField } from "../../ui/sheet/SelectField";
import { SheetModal } from "../../ui/sheet/SheetModal";

const GREEN_50 = "#EFFAF5";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_150 = "#E7EBEF";
const GRAY_200 = "#DDE2E8";
const GRAY_400 = "#98A2AE";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const GRAY_900 = "#16202B";
const FOCUS_RING = "0 0 0 3px rgba(66,168,130,.32)";

// docs/design/animations/animations.md — même durée de sortie que SheetModal.
const CLOSE_NAVIGATE_DELAY_MS = 260;

// Symfony attend Y-m-d\TH:i:sP (business_datetime_immutable, même convention que
// DeclareMissionPage) — jamais de Date.toISOString() qui glisserait en UTC (§16, bug
// historique Planning V2).
function toBackendDateTime(d: dayjs.Dayjs): string {
  return d.second(0).millisecond(0).format("YYYY-MM-DDTHH:mm:ssZ");
}

function minutesToLabel(totalMinutes: number): string {
  const h = Math.floor(totalMinutes / 60);
  const m = totalMinutes % 60;
  return `${String(h).padStart(2, "0")}h${String(m).padStart(2, "0")}`;
}

// Distinct de minutesToLabel (heure d'horloge, toujours 2 chiffres) — une durée peut
// dépasser 24h (ex. "25h00" en cochant "lendemain"), jamais paddée sur les heures.
function formatDuration(minutes: number): string {
  if (!Number.isFinite(minutes) || minutes < 0) return "—";
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `${h}h${String(m).padStart(2, "0")}`;
}

function addHourWithRollover(startMin: number): { end: number; nextDay: boolean } {
  const raw = startMin + 60;
  return raw > 1425 ? { end: raw - 1440, nextDay: true } : { end: raw, nextDay: false };
}

/**
 * Demande de mission chirurgien (Lot 5, D-099) — même recette visuelle et mêmes
 * primitives que DeclareMissionPage (instrumentiste) : un seul formulaire mobile SheetModal,
 * jamais une recopie du formulaire manager complet (§15). Différences volontaires :
 * pas de sélecteur chirurgien (toujours l'utilisateur authentifié, jamais un surgeonId
 * client — §5), site limité à ceux auxquels le chirurgien est réellement affilié (§6,
 * revérifié de toute façon côté serveur).
 */
export default function SurgeonMissionRequestFormPage() {
  const navigate = useNavigate();
  const toast = useToast();
  const queryClient = useQueryClient();

  const [visualOpen, setVisualOpen] = React.useState(true);
  const closeAndNavigate = React.useCallback(
    (to: string | number, options?: { replace?: boolean }, afterClose?: () => void) => {
      setVisualOpen(false);
      setTimeout(() => {
        afterClose?.();
        if (typeof to === "number") navigate(to);
        else navigate(to, options);
      }, CLOSE_NAVIGATE_DELAY_MS);
    },
    [navigate],
  );
  const handleClose = React.useCallback(() => closeAndNavigate(-1), [closeAndNavigate]);

  const { data: me, isLoading: isLoadingMe } = useQuery({
    queryKey: ["me"],
    queryFn: fetchMe,
  });
  const sites = me?.sites ?? [];

  const defaultStartMinutes = React.useMemo(() => {
    const d = dayjs();
    const raw = d.hour() * 60 + d.minute();
    return Math.min(1425, Math.ceil(raw / 15) * 15);
  }, []);
  const defaultEnd = React.useMemo(() => addHourWithRollover(defaultStartMinutes), [defaultStartMinutes]);

  const [siteId, setSiteId] = React.useState<number | null>(null);
  const [type, setType] = React.useState<MissionType>("BLOCK");
  const [dateOffsetDays, setDateOffsetDays] = React.useState(1);
  const [startMinutes, setStartMinutes] = React.useState(defaultStartMinutes);
  const [endMinutes, setEndMinutes] = React.useState(defaultEnd.end);
  const [nextDay, setNextDay] = React.useState(defaultEnd.nextDay);
  const [comment, setComment] = React.useState("");

  React.useEffect(() => {
    if (siteId === null && sites.length > 0) {
      setSiteId(sites[0].id);
    }
  }, [sites, siteId]);

  const dateLabel = dayjs()
    .add(dateOffsetDays, "day")
    .format("ddd D MMMM")
    .replace(/^\w/, (c) => c.toUpperCase());
  const startLabel = minutesToLabel(startMinutes);
  const endLabel = minutesToLabel(endMinutes) + (nextDay ? " (+1j)" : "");

  const startAt = React.useMemo(
    () => dayjs().add(dateOffsetDays, "day").startOf("day").add(startMinutes, "minute"),
    [dateOffsetDays, startMinutes],
  );
  const endAt = React.useMemo(
    () => dayjs().add(dateOffsetDays, "day").startOf("day").add(nextDay ? 1 : 0, "day").add(endMinutes, "minute"),
    [dateOffsetDays, endMinutes, nextDay],
  );

  const dateMinus = () => setDateOffsetDays((v) => Math.max(0, v - 1));
  const datePlus = () => setDateOffsetDays((v) => v + 1);
  const startMinus = () => setStartMinutes(Math.max(0, startMinutes - 15));
  const startPlus = () => setStartMinutes(Math.min(nextDay ? 1425 : endMinutes - 15, startMinutes + 15));
  const endMinus = () => setEndMinutes(Math.max(nextDay ? 0 : startMinutes + 15, endMinutes - 15));
  const endPlus = () => setEndMinutes(Math.min(1425, endMinutes + 15));

  const mutation = useMutation({
    mutationFn: async () => {
      if (siteId === null) {
        throw new Error("Sélectionnez un site.");
      }
      return createMyMissionRequest({
        siteId,
        type,
        startAt: toBackendDateTime(startAt),
        endAt: toBackendDateTime(endAt),
        comment: comment.trim() || undefined,
      });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["surgeon-mission-requests"] });
      closeAndNavigate("/app/s/requests", { replace: true }, () => {
        toast.success("Demande envoyée. Le manager va l'examiner.");
      });
    },
    onError: (err: any) => {
      const msg = err?.response?.data?.error?.message ?? "Une erreur est survenue. Veuillez réessayer.";
      toast.error(msg);
    },
  });

  const submitBusy = mutation.isPending;
  const canSubmit = !isLoadingMe && !submitBusy && siteId !== null;

  return (
    <SheetModal
      open={visualOpen}
      title="Demander une mission"
      onClose={handleClose}
      closeDisabled={submitBusy}
      closeVariant="back"
      mobileMaxHeight="calc(100vh - 24px)"
    >
      <Box sx={{ mt: "4px", fontSize: 13.5, color: GRAY_500, lineHeight: 1.4 }}>
        Le manager examine votre demande et crée la mission si elle est acceptée.
      </Box>

      <Stack spacing="9px" sx={{ mt: "12px" }}>
        <SelectField
          id="mission-request-site"
          label="Site *"
          placeholder={sites.length === 0 && !isLoadingMe ? "Aucun site affilié" : "Sélectionner un site…"}
          value={siteId}
          onChange={setSiteId}
          disabled={isLoadingMe || submitBusy || sites.length === 0}
          options={sites.map((s) => ({ value: s.id, label: s.name }))}
        />

        <SelectField
          id="mission-request-type"
          label="Type"
          placeholder="Sélectionner un type…"
          value={type}
          onChange={setType}
          disabled={submitBusy}
          options={[
            { value: "BLOCK", label: "Bloc opératoire" },
            { value: "CONSULTATION", label: "Consultation" },
          ]}
        />

        <Box sx={{ borderTop: "1px dashed", borderColor: GRAY_150 }} />

        <StepperRow
          label="Date"
          value={dateLabel}
          onMinus={dateMinus}
          onPlus={datePlus}
          minusDisabled={submitBusy || dateOffsetDays <= 0}
          plusDisabled={submitBusy}
          minusAriaLabel="Jour précédent"
          plusAriaLabel="Jour suivant"
        />
        <StepperRow
          label="Début"
          value={startLabel}
          onMinus={startMinus}
          onPlus={startPlus}
          minusDisabled={submitBusy || startMinutes <= 0}
          plusDisabled={submitBusy || startMinutes >= (nextDay ? 1425 : endMinutes - 15)}
          minusAriaLabel="Reculer l'heure de début"
          plusAriaLabel="Avancer l'heure de début"
        />
        <StepperRow
          label="Fin"
          value={endLabel}
          onMinus={endMinus}
          onPlus={endPlus}
          minusDisabled={submitBusy || endMinutes <= (nextDay ? 0 : startMinutes + 15)}
          plusDisabled={submitBusy || endMinutes >= 1425}
          minusAriaLabel="Reculer l'heure de fin"
          plusAriaLabel="Avancer l'heure de fin"
        />

        <CheckboxRow
          checked={nextDay}
          onChange={() => setNextDay((v) => !v)}
          ariaLabel="Se termine le lendemain"
          indent={86}
          label={
            <>
              Se termine le lendemain{" "}
              <Box component="span" sx={{ color: GRAY_400, fontWeight: 400 }}>(après minuit)</Box>
            </>
          }
        />

        <Box
          sx={{
            background: GREEN_50, borderRadius: "13px", padding: "10px 16px",
            display: "flex", alignItems: "center", justifyContent: "space-between",
          }}
        >
          <Box sx={{ fontSize: 14, fontWeight: 700, color: GREEN_800 }}>Durée</Box>
          <Box sx={{ fontSize: 22, fontWeight: 800, color: GREEN_800, fontVariantNumeric: "tabular-nums" }}>
            {formatDuration(Math.max(0, endMinutes + (nextDay ? 1440 : 0) - startMinutes))}
          </Box>
        </Box>

        <Box sx={{ display: "flex", flexDirection: "column", gap: "7px" }}>
          <Box component="label" htmlFor="mission-request-comment" sx={{ fontSize: 13, fontWeight: 700, color: GRAY_700 }}>
            Commentaire{" "}
            <Box component="span" sx={{ fontWeight: 400, color: GRAY_400 }}>(optionnel)</Box>
          </Box>
          <Box
            component="input"
            id="mission-request-comment"
            name="mission-request-comment"
            value={comment}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => setComment(e.target.value)}
            placeholder="Ex. bloc supplémentaire"
            disabled={submitBusy}
            sx={{
              height: 44, border: "1.5px solid", borderColor: GRAY_200, borderRadius: "12px",
              padding: "0 14px", fontFamily: "inherit", fontSize: 15, color: GRAY_900,
              background: "#fff", outline: "none", width: "100%",
              "&:focus": { borderColor: GREEN_700, boxShadow: FOCUS_RING },
              "&:disabled": { opacity: 0.6, cursor: "default" },
            }}
          />
        </Box>
      </Stack>

      <Box
        component="button"
        type="button"
        onClick={() => mutation.mutate()}
        disabled={!canSubmit}
        sx={{
          mt: "12px", width: "100%", height: 52, border: "none", borderRadius: "12px",
          background: GREEN_700, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
          cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
          "&:hover": { background: GREEN_800 },
          "&:active": { transform: "translateY(0.5px)" },
          "&:disabled": { opacity: 0.6, cursor: "default", boxShadow: "none" },
        }}
      >
        {submitBusy ? "…" : "Envoyer"}
      </Box>
    </SheetModal>
  );
}
