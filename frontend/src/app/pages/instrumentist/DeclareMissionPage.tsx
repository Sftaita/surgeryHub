import * as React from "react";
import { useNavigate } from "react-router-dom";
import { useMutation, useQuery } from "@tanstack/react-query";
import { Box, Stack } from "@mui/material";

import dayjs, { Dayjs } from "dayjs";

import type {
  MissionType,
  UserListItem,
  SiteListItem,
} from "../../features/missions/api/missions.types";
import {
  declareMission,
  fetchSites,
  fetchSurgeons,
} from "../../features/missions/api/missions.api";

import { useToast } from "../../ui/toast/useToast";
import { requestMissionSync } from "../../features/missions/sync/missionSyncBus";
import { StepperRow } from "../../ui/sheet/StepperRow";
import { CheckboxRow } from "../../ui/sheet/Checkbox";
import { SelectField } from "../../ui/sheet/SelectField";
import { SheetModal } from "../../ui/sheet/SheetModal";

// docs/design — mêmes valeurs que StepperRow/MobileLayout/MaterialWizard
const GREEN_50 = "#EFFAF5";
const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_150 = "#E7EBEF";
const GRAY_200 = "#DDE2E8";
const GRAY_400 = "#98A2AE";
const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const GRAY_900 = "#16202B";
const FOCUS_RING = "0 0 0 3px rgba(66,168,130,.32)";

// docs/design/animations/animations.md — même durée de sortie que SheetModal (250ms
// mobile) ; on attend la fin de l'animation avant de dénaviguer (unmount de la route).
const CLOSE_NAVIGATE_DELAY_MS = 260;

function buildSurgeonLabel(u: UserListItem): string {
  const display = (u.displayName ?? "").trim();
  if (display) return display;

  const firstname = (u.firstname ?? "").trim();
  const lastname = (u.lastname ?? "").trim();
  const full = `${firstname} ${lastname}`.trim();
  if (full) return full;

  return u.email;
}

// Symfony expected: Y-m-d\TH:i:sP (no ms, with offset)
function toBackendDateTime(d: Dayjs): string {
  return d.second(0).millisecond(0).format("YYYY-MM-DDTHH:mm:ssZ");
}

function formatDuration(minutes: number): string {
  if (!Number.isFinite(minutes) || minutes < 0) return "—";
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  const mm = String(m).padStart(2, "0");
  return `${h}h${mm}`;
}

// docs/design/prototypes/SurgeryHub App v2.dc.html — fmtT: "HH'h'MM"
function minutesToLabel(totalMinutes: number): string {
  const h = Math.floor(totalMinutes / 60);
  const m = totalMinutes % 60;
  return `${String(h).padStart(2, "0")}h${String(m).padStart(2, "0")}`;
}

// idem — start = now arrondi au 1/4h supérieur (borné à 23h45), end = start + 1h avec bascule lendemain
function addHourWithRollover(startMin: number): { end: number; nextDay: boolean } {
  const raw = startMin + 60;
  return raw > 1425 ? { end: raw - 1440, nextDay: true } : { end: raw, nextDay: false };
}

export default function DeclareMissionPage() {
  const navigate = useNavigate();
  const toast = useToast();

  // La route reste montée le temps de l'animation de sortie du sheet (SheetModal
  // ne peut pas la jouer seul : le router démonte la page dès qu'on navigue). Utilisé
  // à la fois pour Annuler/flèche retour ET pour la redirection de succès, pour que
  // la sortie soit toujours l'inverse de l'entrée (jamais de coupure brutale).
  //
  // Le toast de succès est lui aussi différé jusqu'à ce point (voir onSuccess) : le
  // sheet occupe désormais presque tout l'écran (fix "remonter jusqu'en haut"), donc
  // le toast fixed bottom:98px tombait AU MILIEU du sheet encore visible pendant sa
  // sortie — deux animations superposées au même endroit. En l'affichant seulement
  // une fois le sheet réellement fermé, elles ne se chevauchent plus jamais.
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

  const defaultStartMinutes = React.useMemo(() => {
    const d = dayjs();
    const raw = d.hour() * 60 + d.minute();
    return Math.min(1425, Math.ceil(raw / 15) * 15);
  }, []);
  const defaultEnd = React.useMemo(
    () => addHourWithRollover(defaultStartMinutes),
    [defaultStartMinutes],
  );

  const [siteId, setSiteId] = React.useState<number | null>(null);
  const [surgeonUserId, setSurgeonUserId] = React.useState<number | null>(null);
  const [type, setType] = React.useState<MissionType>("BLOCK");

  // docs/design/prototypes/SurgeryHub App v2.dc.html — decl state: dOff (jours depuis
  // aujourd'hui, jamais > 0), start/end en minutes depuis minuit, nextDay pour le
  // chevauchement de minuit. startAt/endAt réels dérivés seulement à la soumission.
  const [dateOffsetDays, setDateOffsetDays] = React.useState(0);
  const [startMinutes, setStartMinutes] = React.useState(defaultStartMinutes);
  const [endMinutes, setEndMinutes] = React.useState(defaultEnd.end);
  const [nextDay, setNextDay] = React.useState(defaultEnd.nextDay);

  const [comment, setComment] = React.useState<string>("");

  const { data: sites, isLoading: isLoadingSites } = useQuery({
    queryKey: ["sites", "list"],
    queryFn: () => fetchSites(),
  });

  const { data: surgeonsPage, isLoading: isLoadingSurgeons } = useQuery({
    queryKey: ["surgeons", "list"],
    queryFn: () => fetchSurgeons(1, 200),
  });

  const sitesOptions: SiteListItem[] = sites ?? [];
  const surgeons = surgeonsPage?.items ?? [];

  const isBusy = isLoadingSites || isLoadingSurgeons;

  // docs/design/prototypes — dEndEff/dDur exactement comme le prototype (pas de
  // plafond 24h explicite : les bornes des steppers ci-dessous l'empêchent en pratique).
  const endMinutesEffective = endMinutes + (nextDay ? 1440 : 0);
  const durationMinutes = Math.max(0, endMinutesEffective - startMinutes);

  const dateLabel = dayjs()
    .add(dateOffsetDays, "day")
    .format("ddd D MMMM")
    .replace(/^\w/, (c) => c.toUpperCase());
  const startLabel = minutesToLabel(startMinutes);
  const endLabel = minutesToLabel(endMinutes) + (nextDay ? " (+1j)" : "");

  const startAt = React.useMemo(
    () =>
      dayjs()
        .add(dateOffsetDays, "day")
        .startOf("day")
        .add(startMinutes, "minute"),
    [dateOffsetDays, startMinutes],
  );
  const endAt = React.useMemo(
    () =>
      dayjs()
        .add(dateOffsetDays, "day")
        .startOf("day")
        .add(nextDay ? 1 : 0, "day")
        .add(endMinutes, "minute"),
    [dateOffsetDays, endMinutes, nextDay],
  );

  const dateMinus = () => setDateOffsetDays((v) => v - 1);
  const datePlus = () => setDateOffsetDays((v) => Math.min(0, v + 1));
  const startMinus = () => setStartMinutes(Math.max(0, startMinutes - 15));
  const startPlus = () =>
    setStartMinutes(
      Math.min(nextDay ? 1425 : endMinutes - 15, startMinutes + 15),
    );
  const endMinus = () =>
    setEndMinutes(
      Math.max(nextDay ? 0 : startMinutes + 15, endMinutes - 15),
    );
  const endPlus = () => setEndMinutes(Math.min(1425, endMinutes + 15));
  const toggleNextDay = () => setNextDay((v) => !v);

  const mutation = useMutation({
    mutationFn: async () => {
      // docs/design/prototypes — declSave : un seul message toast, pas de bannière
      // inline (les SelectField eux-mêmes n'ont pas d'état d'erreur visuel).
      if (siteId === null || surgeonUserId === null) {
        throw new Error("Sélectionnez un site et un chirurgien.");
      }

      return declareMission({
        siteId,
        surgeonUserId,
        type,
        startAt: toBackendDateTime(startAt),
        endAt: toBackendDateTime(endAt),
        comment: comment.trim(), // optionnel
      });
    },
    onSuccess: (mission) => {
      requestMissionSync();
      closeAndNavigate(`/app/i/missions/${mission.id}`, { replace: true }, () => {
        toast.success("Mission déclarée. En cours de validation.");
      });
    },
    onError: (err: any) => {
      // eslint-disable-next-line no-console
      console.error("DeclareMission error:", err);

      const msg =
        typeof err?.message === "string" && err.message
          ? err.message
          : "Une erreur est survenue lors de la déclaration. Veuillez réessayer.";

      toast.error(msg);
    },
  });

  const submitBusy = mutation.isPending;

  const canSubmit =
    !isBusy && !submitBusy && siteId !== null && surgeonUserId !== null;

  return (
    <SheetModal
      open={visualOpen}
      title="Déclarer une mission"
      onClose={handleClose}
      closeDisabled={submitBusy}
      closeVariant="back"
      mobileMaxHeight="calc(100vh - 24px)"
    >
      <Box sx={{ mt: "4px", fontSize: 13.5, color: GRAY_500, lineHeight: 1.4 }}>
        Mission effectuée hors plateforme ou non prévue au planning.
      </Box>

      <Stack spacing="9px" sx={{ mt: "12px" }}>
        <SelectField
          id="declare-site"
          label="Site *"
          placeholder="Sélectionner un site…"
          value={siteId}
          onChange={setSiteId}
          disabled={isBusy || submitBusy}
          options={sitesOptions.map((s) => ({ value: s.id, label: s.name }))}
        />

        <SelectField
          id="declare-surgeon"
          label="Chirurgien *"
          placeholder="Sélectionner un chirurgien…"
          value={surgeonUserId}
          onChange={setSurgeonUserId}
          disabled={isBusy || submitBusy}
          options={surgeons.map((u) => ({ value: u.id, label: buildSurgeonLabel(u) }))}
        />

        <SelectField
          id="declare-type"
          label="Type"
          placeholder="Sélectionner un type…"
          value={type}
          onChange={setType}
          disabled={isBusy || submitBusy}
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
          minusDisabled={isBusy || submitBusy}
          plusDisabled={isBusy || submitBusy || dateOffsetDays >= 0}
          minusAriaLabel="Jour précédent"
          plusAriaLabel="Jour suivant"
        />
        <StepperRow
          label="Début"
          value={startLabel}
          onMinus={startMinus}
          onPlus={startPlus}
          minusDisabled={isBusy || submitBusy || startMinutes <= 0}
          plusDisabled={
            isBusy ||
            submitBusy ||
            startMinutes >= (nextDay ? 1425 : endMinutes - 15)
          }
          minusAriaLabel="Reculer l'heure de début"
          plusAriaLabel="Avancer l'heure de début"
        />
        <StepperRow
          label="Fin"
          value={endLabel}
          onMinus={endMinus}
          onPlus={endPlus}
          minusDisabled={
            isBusy ||
            submitBusy ||
            endMinutes <= (nextDay ? 0 : startMinutes + 15)
          }
          plusDisabled={isBusy || submitBusy || endMinutes >= 1425}
          minusAriaLabel="Reculer l'heure de fin"
          plusAriaLabel="Avancer l'heure de fin"
        />

        <CheckboxRow
          checked={nextDay}
          onChange={toggleNextDay}
          ariaLabel="Se termine le lendemain"
          indent={86}
          label={
            <>
              Se termine le lendemain{" "}
              <Box component="span" sx={{ color: GRAY_400, fontWeight: 400 }}>
                (après minuit)
              </Box>
            </>
          }
        />

        <Box
          sx={{
            background: GREEN_50,
            borderRadius: "13px",
            padding: "10px 16px",
            display: "flex",
            alignItems: "center",
            justifyContent: "space-between",
          }}
        >
          <Box sx={{ fontSize: 14, fontWeight: 700, color: GREEN_800 }}>
            Durée
          </Box>
          <Box
            sx={{
              fontSize: 22,
              fontWeight: 800,
              color: GREEN_800,
              fontVariantNumeric: "tabular-nums",
            }}
          >
            {formatDuration(durationMinutes)}
          </Box>
        </Box>

        <Box sx={{ display: "flex", flexDirection: "column", gap: "7px" }}>
          <Box component="label" htmlFor="declare-comment" sx={{ fontSize: 13, fontWeight: 700, color: GRAY_700 }}>
            Commentaire{" "}
            <Box component="span" sx={{ fontWeight: 400, color: GRAY_400 }}>
              (optionnel)
            </Box>
          </Box>
          <Box
            component="input"
            id="declare-comment"
            name="declare-comment"
            value={comment}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => setComment(e.target.value)}
            placeholder="Ex. remplacement de dernière minute"
            disabled={isBusy || submitBusy}
            sx={{
              height: 44, border: "1.5px solid", borderColor: GRAY_200, borderRadius: "12px",
              padding: "0 14px", fontFamily: "inherit", fontSize: 15, color: GRAY_900,
              background: "#fff", outline: "none", width: "100%",
              "&:focus": { borderColor: GREEN_500, boxShadow: FOCUS_RING },
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
        {submitBusy ? "…" : "Déclarer la mission"}
      </Box>
    </SheetModal>
  );
}
