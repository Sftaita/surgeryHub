import * as React from "react";
import { Box, CircularProgress, Tooltip } from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { fetchMissionById, getMissionExecution, remindMissionHours } from "../../missions/api/missions.api";
import { fetchMissionAudit } from "../../planning-v2/api/planningV2.api";
import { fetchMissionEncoding, remindMissionEncoding, validateMissionEncoding } from "../../encoding/api/encoding.api";
import { EncodingContentPanel } from "./EncodingContentPanel";
import { useToast } from "../../../ui/toast/useToast";
import { resolveApiAssetUrl } from "../../../api/apiAssetUrl";
import type { EncodingTrackingItem } from "../api/encodingTracking.api";
import { EFFECTIVE_SOURCE_LABEL, HOURS_COMPARISON_TONE, formatMinutes } from "../encodingStateMeta";

const GRAY_950 = "#0B1320";
const GRAY_800 = "#2A3643";
const GRAY_700 = "#3A4754";
const GRAY_600 = "#5A6675";
const GRAY_500 = "#727E8C";
const GRAY_400 = "#98A2AE";
const GRAY_200 = "#DDE2E8";
const GRAY_150 = "#E7EBEF";
const GRAY_100 = "#EEF1F4";
const GRAY_75 = "#F1F4F7";
const GRAY_50 = "#F5F7FA";
const GREEN_800 = "#1F6B4F";
const GREEN_700 = "#2C7D5F";
const GREEN_600 = "#338F6E";
const GREEN_500 = "#42A882";
const GREEN_100 = "#DDF4EA";
const AMBER_700 = "#B7791F";
const AMBER_100 = "#FBEACB";
const AMBER_50 = "#FEF6E7";
const RED_700 = "#C62F36";
const RED_50 = "#FDEEEE";
const SHADOW_LG = "-24px 0 60px -20px rgba(11,19,32,.45)";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

const STATE_TONE: Record<string, { bg: string; fg: string }> = {
  UPCOMING: { bg: GRAY_75, fg: GRAY_400 },
  TO_ENCODE: { bg: "#F0A91B", fg: GRAY_950 },
  IN_PROGRESS: { bg: AMBER_100, fg: AMBER_700 },
  SUBMITTED: { bg: "#EDF4FF", fg: "#1B5FD0" },
  VALIDATED: { bg: GREEN_100, fg: GREEN_800 },
  LOCKED: { bg: GREEN_100, fg: GREEN_800 },
  NOT_APPLICABLE: { bg: GRAY_100, fg: GRAY_500 },
};

function initials(name: string): string {
  return name.split(/[\s-]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase();
}

function extractErrorMessage(err: any): string {
  return err?.response?.data?.message ?? err?.response?.data?.detail ?? err?.message ?? String(err);
}

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("fr-BE", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
}

interface StepInfo { label: string; done: boolean; when: string; }

function buildSteps(item: EncodingTrackingItem, auditEvents: { eventType: string; occurredAt: string }[] | undefined): StepInfo[] {
  const findWhen = (type: string) => auditEvents?.find((e) => e.eventType === type)?.occurredAt;

  const draftDone = !["UPCOMING", "TO_ENCODE"].includes(item.encodingState);
  const submittedDone = ["SUBMITTED", "VALIDATED", "LOCKED"].includes(item.encodingState);
  const validatedDone = ["VALIDATED", "LOCKED"].includes(item.encodingState);

  // Une date n'accompagne qu'une étape atteinte : après une réouverture, l'audit garde
  // l'ancienne soumission/validation, qui ne doit pas se lire comme l'état courant.
  return [
    { label: "Brouillon", done: draftDone, when: draftDone ? formatDateTime(findWhen("MISSION_ENCODING_STARTED")) : "—" },
    { label: "Soumis", done: submittedDone, when: submittedDone ? formatDateTime(findWhen("MISSION_ENCODING_COMPLETED")) : "—" },
    { label: "Validé", done: validatedDone, when: validatedDone ? formatDateTime(findWhen("MISSION_ENCODING_VALIDATED")) : "—" },
  ];
}

interface Props {
  item: EncodingTrackingItem | null;
  onClose: () => void;
}

/**
 * Tiroir de détail — maquette validée docs/design/Instruction design/Suivi-encodages-admin.
 * `item` vient de la liste déjà chargée (EncodingTrackingItem, D-118) : sert immédiatement
 * l'en-tête (état, retard, heures planifiées/effectives) sans attendre une requête
 * supplémentaire. Le corps (interventions/matériel, actions) réutilise EXACTEMENT les
 * mêmes requêtes/mutations déjà éprouvées par MissionDetailPage.tsx — aucune logique
 * métier dupliquée, aucun montant ni chronologie inventés (voir docs/decisions.md D-118).
 *
 * D-118 §22.6 avait choisi que ce module observe sans agir ; élargi ici (D-120) au strict
 * nécessaire de la maquette : Valider (réutilise POST .../encoding/validate) et Relancer
 * (POST .../encoding/remind, D-120). Rejeter/Rouvrir restent sur la fiche mission complète.
 */
export function MissionTrackingDrawer({ item, onClose }: Props) {
  const toast = useToast();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const missionId = item?.missionId ?? null;
  const open = missionId !== null;

  const missionQuery = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId!),
    enabled: open,
  });
  const executionQuery = useQuery({
    queryKey: ["mission-execution", missionId],
    queryFn: () => getMissionExecution(missionId!),
    enabled: open,
  });
  // GET .../encoding est une LECTURE (VIEW_ENCODING, toujours accordé au manager depuis le
  // Lot 6, D-100) : jamais désactivée après validation — le verrou porte sur l'écriture,
  // pas sur la consultation (D-136).
  const encodingQuery = useQuery({
    queryKey: ["missionEncoding", missionId],
    queryFn: () => fetchMissionEncoding(missionId!),
    enabled: open,
  });
  const auditQuery = useQuery({
    queryKey: ["mission-audit", missionId],
    queryFn: () => fetchMissionAudit(missionId!),
    enabled: open,
  });

  // En parallèle, jamais en série : chaque refetch attendu l'un après l'autre retardait
  // d'autant la mise à jour du tiroir (et de la liste qui fournit `item`).
  function refreshAfterAction() {
    return Promise.all([
      queryClient.invalidateQueries({ queryKey: ["mission", missionId] }),
      queryClient.invalidateQueries({ queryKey: ["missionEncoding", missionId] }),
      queryClient.invalidateQueries({ queryKey: ["mission-audit", missionId] }),
      queryClient.invalidateQueries({ queryKey: ["missions"], exact: false }),
      queryClient.invalidateQueries({ queryKey: ["encoding-tracking"], exact: false }),
    ]);
  }

  const validateMutation = useMutation({
    mutationFn: async () => validateMissionEncoding(missionId!),
    onSuccess: async () => { toast.success("Encodage validé."); await refreshAfterAction(); },
    onError: (err: any) => toast.error(err?.response?.status === 403 ? "Accès interdit." : extractErrorMessage(err)),
  });

  const remindMutation = useMutation({
    mutationFn: async () => remindMissionEncoding(missionId!),
    onSuccess: async () => { toast.success("Relance envoyée à l'instrumentiste."); await refreshAfterAction(); },
    onError: (err: any) => toast.error(err?.response?.status === 403 ? "Accès interdit." : extractErrorMessage(err)),
  });

  const remindHoursMutation = useMutation({
    mutationFn: async () => remindMissionHours(missionId!),
    onSuccess: async () => { toast.success("Rappel des heures envoyé à l'instrumentiste."); await refreshAfterAction(); },
    onError: (err: any) => toast.error(err?.response?.status === 403 ? "Accès interdit." : extractErrorMessage(err)),
  });

  // Le tiroir se superpose à la liste (aucun reflow) : Échap le ferme, comme un tiroir MUI.
  React.useEffect(() => {
    if (!open) return;
    const onKeyDown = (e: KeyboardEvent) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [open, onClose]);

  if (!open || !item) return null;

  const tone = STATE_TONE[item.encodingState] ?? STATE_TONE.NOT_APPLICABLE;
  const allowedActions = missionQuery.data?.allowedActions ?? [];
  const canValidate = allowedActions.includes("validate");
  const canRemind = allowedActions.includes("remind");
  // D-136 — décidé uniquement par le backend (MissionHoursReminderPolicy) : jamais déduit
  // ici de hasRealHours ou de la date.
  const canRemindHours = allowedActions.includes("remind_hours");
  const isValidated = item.encodingState === "VALIDATED" || item.encodingState === "LOCKED";
  const entries = encodingQuery.data?.entries ?? [];
  const refs = item.encoding.materialLineCount;
  const steps = buildSteps(item, auditQuery.data);

  const planRange = item.startAt && item.endAt
    ? `${formatDateTime(item.startAt).slice(-5)} → ${formatDateTime(item.endAt).slice(-5)}`
    : "—";
  const realRange = executionQuery.data?.actualStartAt && executionQuery.data?.actualEndAt
    ? `${formatDateTime(executionQuery.data.actualStartAt).slice(-5)} → ${formatDateTime(executionQuery.data.actualEndAt).slice(-5)}`
    : item.hours.hasRealHours ? EFFECTIVE_SOURCE_LABEL[item.hours.effectiveSource] : HOURS_COMPARISON_TONE.NO_REAL_HOURS.label;
  const hoursTone = HOURS_COMPARISON_TONE[item.hours.comparison];
  const realPct = item.hours.plannedMinutes > 0
    ? Math.min(100, Math.round((item.hours.effectiveMinutes / item.hours.plannedMinutes) * 100))
    : 0;

  const reminderTooltip = [
    missionQuery.data?.automaticReminderSentAt
      ? `Relance automatique envoyée le ${formatDateTime(missionQuery.data.automaticReminderSentAt)}`
      : missionQuery.data?.nextAutomaticReminderAt
        ? `Prochaine relance automatique : ${formatDateTime(missionQuery.data.nextAutomaticReminderAt)}`
        : null,
    missionQuery.data?.lastManualReminderAt
      ? `Dernière relance manuelle le ${formatDateTime(missionQuery.data.lastManualReminderAt)} par ${missionQuery.data.lastManualReminderByName ?? "—"}`
      : null,
  ].filter(Boolean).join(" · ") || "Aucune relance envoyée pour l'instant.";

  // Dernier rappel des heures : lu dans l'audit déjà chargé (MISSION_HOURS_MANUAL_REMINDER_SENT).
  const lastHoursReminder = (auditQuery.data ?? [])
    .filter((e) => e.eventType === "MISSION_HOURS_MANUAL_REMINDER_SENT")
    .reduce<{ occurredAt: string; actorName: string | null } | null>((last, e) => (!last || e.occurredAt > last.occurredAt ? e : last), null);
  const hoursReminderTooltip = lastHoursReminder
    ? `Dernier rappel des heures le ${formatDateTime(lastHoursReminder.occurredAt)} par ${lastHoursReminder.actorName ?? "—"}`
    : "Demande à l'instrumentiste de renseigner ses heures réellement prestées (distinct de la relance d'encodage).";

  return (
    <Box
      role="dialog"
      aria-modal={false}
      aria-label={`Détail de la mission #${item.missionId}`}
      data-testid="mission-tracking-drawer"
      sx={{
        position: "fixed", top: 0, right: 0, bottom: 0, width: 560, maxWidth: "100vw", background: GRAY_50, boxShadow: SHADOW_LG,
        display: "flex", flexDirection: "column", zIndex: 20,
      }}
    >
      <Box sx={{ flexShrink: 0, background: "#fff", padding: "16px 20px 0", display: "flex", alignItems: "flex-start", gap: "12px" }}>
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Box sx={{ display: "flex", alignItems: "center", gap: "9px", flexWrap: "wrap" }}>
            <Box component="h2" sx={{ m: 0, fontSize: 19, fontWeight: 800, letterSpacing: "-.01em", color: GRAY_950 }}>
              Mission #{item.missionId}
            </Box>
            <Box sx={{ display: "inline-flex", alignItems: "center", height: 24, padding: "0 10px", borderRadius: "999px", fontSize: 11.5, fontWeight: 800, background: tone.bg, color: tone.fg }}>
              {item.encodingStateLabel}
            </Box>
            {item.encoding.isStale && (
              <Box sx={{ display: "inline-flex", alignItems: "center", height: 24, padding: "0 8px", borderRadius: "999px", fontSize: 11.5, fontWeight: 800, background: RED_50, color: RED_700 }}>
                en retard
              </Box>
            )}
          </Box>
          <Box sx={{ mt: "5px", fontSize: 12.5, color: GRAY_500 }}>
            {item.site?.name ?? "—"} · {planRange}
          </Box>
        </Box>
        <Box
          component="button"
          type="button"
          onClick={onClose}
          aria-label="Fermer"
          sx={{ width: 32, height: 32, flexShrink: 0, display: "grid", placeItems: "center", border: 0, borderRadius: "9px", background: GRAY_100, color: GRAY_600, cursor: "pointer" }}
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
        </Box>
      </Box>

      <Box sx={{ flexShrink: 0, display: "flex", alignItems: "center", gap: "14px", padding: "13px 20px 14px", background: "#fff", borderBottom: "1px solid", borderColor: GRAY_150 }}>
        <Person name={item.instrumentist?.name ?? "—"} photoPath={item.instrumentist?.photoPath} role="Instrumentiste" green />
        <Person name={item.surgeon?.name ?? "—"} photoPath={item.surgeon?.photoPath} role="Chirurgien" />
      </Box>

      {/* Un seul défilement, celui du tiroir : la carte Interventions prend la hauteur de son
          contenu (jamais d'ascenseur imbriqué sur un écran bas) ; sur un écran haut, elle
          remplit l'espace restant. */}
      <Box sx={{ flex: 1, minHeight: 0, overflowY: "auto", padding: "16px 20px", display: "flex", flexDirection: "column", gap: "14px" }}>
        <Card>
          <Box sx={{ display: "flex", alignItems: "center", gap: "10px", minHeight: 28 }}>
            <Eyebrow>HEURES</Eyebrow>
            <Box data-testid="drawer-hours-status" data-hours-comparison={item.hours.comparison} sx={{ fontSize: 11.5, fontWeight: 800, color: hoursTone.fg }}>
              {hoursTone.label}
            </Box>
            {canRemindHours && (
              <Tooltip title={hoursReminderTooltip} describeChild>
                <Box
                  component="button"
                  type="button"
                  onClick={() => remindHoursMutation.mutate()}
                  disabled={remindHoursMutation.isPending}
                  sx={{
                    ml: "auto", display: "flex", alignItems: "center", gap: "6px", height: 28, padding: "0 10px", borderRadius: "8px",
                    border: "1px solid", borderColor: AMBER_100, background: AMBER_50, color: AMBER_700, font: "inherit",
                    fontSize: 12, fontWeight: 800, cursor: "pointer", "&:hover": { background: AMBER_100 },
                  }}
                >
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                  Rappeler les heures
                </Box>
              </Tooltip>
            )}
          </Box>
          <Box sx={{ display: "flex", flexDirection: "column", gap: "7px" }}>
            <Bar label="Planifié" text={planRange} total={formatMinutes(item.hours.plannedMinutes)} pct={100} color={GRAY_400} plan />
            <Bar label="Réel" text={realRange} total={item.hours.hasRealHours ? formatMinutes(item.hours.effectiveMinutes) : "—"} totalColor={hoursTone.fg} pct={realPct} color={hoursTone.bar} />
          </Box>
        </Card>

        <Card sx={{ flexDirection: "row", alignItems: "center", gap: "10px", padding: "13px 16px 14px" }}>
          {steps.map((s) => (
            <Box key={s.label} sx={{ flex: 1, display: "flex", flexDirection: "column", gap: "6px" }}>
              <Box sx={{ height: 4, borderRadius: "99px", background: s.done ? GREEN_500 : GRAY_200 }} />
              <Box sx={{ fontSize: 11.5, fontWeight: 800, color: s.done ? GREEN_800 : GRAY_400 }}>{s.label}</Box>
              <Box sx={{ fontSize: 11, color: GRAY_400 }}>{s.when}</Box>
            </Box>
          ))}
        </Card>

        <Card sx={{ flex: "1 0 auto", padding: 0, gap: 0 }}>
          <Box sx={{ padding: "13px 16px 11px", borderBottom: "1px solid", borderColor: GRAY_150, display: "flex", alignItems: "center", flexWrap: "wrap", columnGap: "10px", rowGap: "4px" }}>
            <Eyebrow>INTERVENTIONS &amp; MATÉRIEL</Eyebrow>
            {isValidated && (
              <Box sx={{ flexShrink: 0, whiteSpace: "nowrap", height: 20, padding: "0 8px", borderRadius: "999px", display: "inline-flex", alignItems: "center", fontSize: 10.5, fontWeight: 800, background: GRAY_100, color: GRAY_600 }}>
                Lecture seule
              </Box>
            )}
            <Box sx={{ ml: "auto", fontSize: 12, fontWeight: 700, color: GRAY_500, fontVariantNumeric: "tabular-nums", whiteSpace: "nowrap" }}>
              {item.encoding.interventionCount === 0
                ? "rien d'encodé"
                : `${item.encoding.encodedInterventionCount}/${item.encoding.interventionCount} intervention${item.encoding.interventionCount > 1 ? "s" : ""} encodée${item.encoding.interventionCount > 1 ? "s" : ""} · ${refs} référence${refs > 1 ? "s" : ""}`}
            </Box>
          </Box>
          <Box sx={{ padding: "12px 16px 16px", display: "flex", flexDirection: "column", gap: "10px" }}>
            {encodingQuery.isLoading && (
              <Box sx={{ p: 2, textAlign: "center" }}><CircularProgress size={20} /></Box>
            )}
            {encodingQuery.isError && (
              <Box sx={{ padding: "14px", borderRadius: "12px", background: RED_50, fontSize: 12.5, fontWeight: 600, color: RED_700 }}>
                Impossible de charger le détail de l'encodage.
              </Box>
            )}
            {!encodingQuery.isLoading && entries.length > 0 && <EncodingContentPanel entries={entries} />}
            {isValidated && encodingQuery.isSuccess && entries.length === 0 && (
              <Box sx={{ padding: "14px", borderRadius: "12px", background: GRAY_75, fontSize: 12.5, fontWeight: 600, color: GRAY_600 }}>
                Aucune intervention encodée.
              </Box>
            )}
            {!isValidated && encodingQuery.isSuccess && entries.length === 0 && item.encoding.interventionCount === 0 && (
              <Box sx={{ display: "flex", alignItems: "center", gap: "10px", padding: "14px", borderRadius: "12px", background: AMBER_50, border: "1px solid", borderColor: AMBER_100, fontSize: 12.5, fontWeight: 600, color: AMBER_700 }}>
                Aucun matériel encodé — l'instrumentiste n'a pas encore ouvert son encodage.
              </Box>
            )}
          </Box>
        </Card>
      </Box>

      <Box sx={{ flexShrink: 0, background: "#fff", borderTop: "1px solid", borderColor: GRAY_150, padding: "13px 20px", display: "flex", alignItems: "center", gap: "10px" }}>
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Box sx={{ fontSize: 12.5, fontWeight: 800, color: GRAY_950 }}>
            {canValidate ? "Prêt à valider" : isValidated ? "Validé" : "Encodage en cours"}
          </Box>
          <Box sx={{ mt: "2px", fontSize: 11.5, color: GRAY_500 }}>
            <Box
              component="button"
              type="button"
              onClick={() => navigate(`/app/m/missions/${item.missionId}`)}
              sx={{ border: 0, background: "none", font: "inherit", fontSize: 11.5, color: GREEN_700, fontWeight: 700, cursor: "pointer", p: 0 }}
            >
              Voir la fiche complète
            </Box>
          </Box>
        </Box>

        {canRemind && (
          <Tooltip title={reminderTooltip} describeChild>
            <Box
              component="button"
              type="button"
              onClick={() => remindMutation.mutate()}
              disabled={remindMutation.isPending}
              sx={{
                display: "flex", alignItems: "center", gap: "6px", height: 40, padding: "0 14px", borderRadius: "11px",
                border: "1px solid", borderColor: GRAY_200, background: "#fff", color: GRAY_700, font: "inherit",
                fontSize: 13, fontWeight: 700, cursor: "pointer",
                "&:hover": { background: GRAY_75 },
              }}
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="M4 4v6h6" /><path d="M20 12a8 8 0 1 1-2.3-5.7L20 9" /></svg>
              Relancer
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 8h.01M11 12h1v4h1" /></svg>
            </Box>
          </Tooltip>
        )}

        <Box
          component="button"
          type="button"
          onClick={() => validateMutation.mutate()}
          disabled={!canValidate || validateMutation.isPending}
          sx={{
            display: "flex", alignItems: "center", justifyContent: "center", gap: "8px", height: 40, padding: "0 18px",
            border: 0, borderRadius: "11px", font: "inherit", fontSize: 13.5, fontWeight: 800, cursor: canValidate ? "pointer" : "default",
            background: item.encodingState === "VALIDATED" || item.encodingState === "LOCKED" ? GREEN_100 : canValidate ? GREEN_600 : GRAY_150,
            color: item.encodingState === "VALIDATED" || item.encodingState === "LOCKED" ? GREEN_800 : canValidate ? "#fff" : GRAY_400,
            "&:hover": canValidate ? { background: GREEN_700 } : undefined,
          }}
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.8" strokeLinecap="round" strokeLinejoin="round"><path d="m5 13 4 4L19 7" /></svg>
          {item.encodingState === "VALIDATED" || item.encodingState === "LOCKED" ? "Encodage validé" : "Valider l'encodage"}
        </Box>
      </Box>
    </Box>
  );
}

function Person({ name, photoPath, role, green }: { name: string; photoPath?: string | null; role: string; green?: boolean }) {
  return (
    <Box sx={{ display: "flex", alignItems: "center", gap: "8px", minWidth: 0 }}>
      <Box sx={{
        width: 28, height: 28, flexShrink: 0, borderRadius: "999px", display: "grid", placeItems: "center", overflow: "hidden",
        fontSize: 10.5, fontWeight: 800, background: green ? GREEN_100 : GRAY_100, color: green ? GREEN_800 : GRAY_600,
      }}>
        {photoPath
          ? <Box component="img" src={resolveApiAssetUrl(photoPath)} alt="" sx={{ width: "100%", height: "100%", objectFit: "cover" }} />
          : initials(name)}
      </Box>
      <Box sx={{ minWidth: 0 }}>
        <Box sx={{ fontSize: 12.5, fontWeight: 700, color: GRAY_800, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{name}</Box>
        <Box sx={{ fontSize: 11, color: GRAY_500 }}>{role}</Box>
      </Box>
    </Box>
  );
}

function Card({ children, sx }: { children: React.ReactNode; sx?: object }) {
  return (
    <Box sx={{
      background: "#fff", border: "1px solid", borderColor: GRAY_150, borderRadius: "14px", boxShadow: SHADOW_XS,
      padding: "14px 16px 16px", display: "flex", flexDirection: "column", gap: "12px", ...sx,
    }}>
      {children}
    </Box>
  );
}

function Eyebrow({ children }: { children: React.ReactNode }) {
  return <Box sx={{ fontSize: 11, fontWeight: 800, letterSpacing: ".09em", color: GRAY_500, whiteSpace: "nowrap" }}>{children}</Box>;
}

function Bar({ label, text, total, totalColor, pct, color, plan }: { label: string; text: string; total: string; totalColor?: string; pct: number; color: string; plan?: boolean }) {
  return (
    <Box sx={{ display: "flex", alignItems: "center", gap: "10px" }}>
      <Box sx={{ width: 56, flexShrink: 0, fontSize: 11.5, fontWeight: 700, color: GRAY_500 }}>{label}</Box>
      <Box sx={{ flex: 1, height: 22, borderRadius: "7px", background: plan ? GRAY_100 : GRAY_75, position: "relative", overflow: "hidden" }}>
        {!plan && <Box sx={{ position: "absolute", inset: "0 auto 0 0", width: `${pct}%`, borderRadius: "7px", background: color }} />}
        <Box sx={{ position: "absolute", left: 9, top: 3, fontSize: 11.5, fontWeight: 700, color: GRAY_700, fontVariantNumeric: "tabular-nums" }}>{text}</Box>
      </Box>
      <Box sx={{ width: 50, flexShrink: 0, textAlign: "right", fontSize: 12.5, fontWeight: 800, color: totalColor ?? GRAY_950, fontVariantNumeric: "tabular-nums" }}>{total}</Box>
    </Box>
  );
}

export default MissionTrackingDrawer;
