import * as React from "react";
import { useQuery } from "@tanstack/react-query";

import { fetchMissionById, getMissionExecution } from "../api/missions.api";
import { fetchMissionEncoding } from "../../encoding/api/encoding.api";
import { fetchMissionAudit } from "../../planning-v2/api/planningV2.api";
import { useMissionManagerActions, type MissionActionDescriptor } from "../hooks/useMissionManagerActions";
import { useMissionFinancialCalculation } from "../../financial-calculation/hooks/useMissionFinancialCalculation";
import { AnomalyReportsManagerPanel } from "../../encoding-anomaly-reports/components/AnomalyReportsManagerPanel";
import { MissionStatusChip, missionStatusLabel } from "../components/MissionsDayList";
import { MissionEncodingOverviewNotice } from "../components/MissionEncodingOverviewNotice";
import { MissionDrawer, financeLabel, type DrawerFooterAction } from "./MissionDrawer";
import { buildMissionDrawerView, FINANCE_STATUSES, NO_ENCODING_STATUSES } from "./missionDrawerModel";

interface Props {
  /** null = tiroir fermé. */
  missionId: number | null;
  onClose: () => void;
}

const TONE: Record<MissionActionDescriptor["tone"], DrawerFooterAction["tone"]> = {
  neutral: "ghost",
  warning: "amber",
  danger: "danger",
  success: "primary",
  primary: "primary",
};

/**
 * Détail mission côté manager — maquette validée docs/design/Instruction design/Mission-drawer.
 * Seul détail manager : liste Missions, plannings instrumentiste/chirurgien, et
 * /app/m/missions/:id qui redirige vers /app/m/missions?mission=:id. Actions partagées
 * (useMissionManagerActions) et calcul financier (useMissionFinancialCalculation) :
 * aucune règle métier propre.
 * Chaque bouton n'apparaît que si le backend l'autorise (allowedActions, statut du calcul).
 */
export function ManagerMissionDrawer({ missionId, onClose }: Props) {
  const open = missionId !== null;

  // Le tiroir garde la dernière mission pendant l'animation de sortie (pas de démontage).
  const lastIdRef = React.useRef<number | null>(missionId);
  if (missionId !== null) lastIdRef.current = missionId;
  const id = lastIdRef.current;
  const enabled = open && id !== null;

  const missionQuery = useQuery({
    queryKey: ["mission", id],
    queryFn: () => fetchMissionById(id!),
    enabled,
  });
  const mission = missionQuery.data;
  const status = String(mission?.status ?? "");

  const executionQuery = useQuery({
    queryKey: ["mission-execution", id],
    queryFn: () => getMissionExecution(id!),
    enabled,
  });
  // GET .../encoding est une lecture (VIEW_ENCODING, D-136) — inutile tant que la mission
  // n'a pas d'encodage (brouillon, pool, annulée).
  const encodingQuery = useQuery({
    queryKey: ["missionEncoding", id],
    queryFn: () => fetchMissionEncoding(id!),
    enabled: enabled && !!mission && !NO_ENCODING_STATUSES.includes(status),
  });
  const auditQuery = useQuery({
    queryKey: ["mission-audit", id],
    queryFn: () => fetchMissionAudit(id!),
    enabled,
  });
  const financeApplicable = FINANCE_STATUSES.includes(status);
  const finance = useMissionFinancialCalculation(id ?? NaN, { enabled: enabled && financeApplicable });

  const { actions, corrections, dialogs, dialogOpen } = useMissionManagerActions(id ?? NaN, mission, { onLeave: onClose });

  // Échap ferme le tiroir et lui seul — sauf pendant une fenêtre de confirmation, qu'elle seule
  // concerne. Capturé sur window avant tout autre gestionnaire : où que soit le focus (il peut
  // retomber hors du tiroir quand le bouton d'une action disparaît), Échap ne doit jamais
  // atteindre le Drawer MUI qui héberge le tiroir (fiche instrumentiste / chirurgien).
  React.useEffect(() => {
    if (!open || dialogOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== "Escape") return;
      e.preventDefault();
      e.stopPropagation();
      onClose();
    };
    window.addEventListener("keydown", onKey, true);
    return () => window.removeEventListener("keydown", onKey, true);
  }, [open, dialogOpen, onClose]);

  const view = mission
    ? buildMissionDrawerView({
        mission,
        execution: executionQuery.data,
        entries: encodingQuery.data?.entries,
        coherenceSummary: encodingQuery.data?.coherenceSummary,
        encodingComments: encodingQuery.data?.encodingComments,
        encodingError: encodingQuery.isError,
        audit: auditQuery.data,
        calculation: finance.active,
        calculationHistory: finance.history,
        calculationLoading: financeApplicable && finance.query.isLoading,
      })
    : null;

  const placeholder = missionQuery.isError
    ? (missionQuery.error as any)?.response?.status === 403
      ? "Accès interdit"
      : (missionQuery.error as any)?.response?.status === 404
        ? "Mission introuvable"
        : "Erreur serveur"
    : "Chargement…";

  // Pied : actions de la mission (allowedActions), puis celles du calcul financier.
  const footerActions: DrawerFooterAction[] = [];
  const secondary = actions.filter((a) => !a.contained);
  const primary = actions.filter((a) => a.contained);
  for (const a of secondary) {
    footerActions.push({ key: a.key, label: a.label, tone: TONE[a.tone], icon: a.key === "reopenEncoding" ? "reopen" : undefined, disabled: a.disabled, testId: a.testId, onClick: a.onClick });
  }
  const active = finance.active;
  if (financeApplicable && active && (active.status === "CALCULATED" || active.status === "APPROVED")) {
    footerActions.push({
      key: "recalculate", label: "Recalculer", tone: "ghost", icon: "retry", testId: "drawer-recalculate",
      disabled: finance.recalculateMutation.isPending, onClick: () => finance.recalculateMutation.mutate(active.id),
    });
  }
  for (const a of primary) {
    footerActions.push({ key: a.key, label: a.label, tone: TONE[a.tone], icon: "check", disabled: a.disabled, testId: a.testId, onClick: a.onClick });
  }
  // D-153 — calcul obsolète : ni approuvable ni verrouillable, seul « Recalculer » reste proposé.
  if (financeApplicable && !finance.query.isLoading) {
    if (!active) {
      footerActions.push({
        key: "calculate", label: "Calculer la valorisation", tone: "primary", icon: "check", testId: "drawer-calculate",
        disabled: finance.calculateMutation.isPending, onClick: () => finance.calculateMutation.mutate(),
      });
    } else if (!active.obsolete && !active.tariffStaleReason && active.status === "CALCULATED") {
      footerActions.push({
        key: "approveCalculation", label: "Approuver le calcul", tone: "primary", icon: "check", testId: "drawer-approve-calculation",
        disabled: finance.approveMutation.isPending, onClick: () => finance.approveMutation.mutate(active.id),
      });
    } else if (!active.obsolete && !active.tariffStaleReason && active.status === "APPROVED") {
      footerActions.push({
        key: "lockCalculation", label: "Verrouiller le calcul", tone: "primary", icon: "check", testId: "drawer-lock-calculation",
        disabled: finance.lockMutation.isPending, onClick: () => finance.lockMutation.mutate(active.id),
      });
    }
    // D-171 — prestation instrumentiste valorisée à part une fois ses heures déclarées (servi par le backend).
    const supplement = finance.supplement;
    if (active?.instrumentistSupplementAvailable) {
      footerActions.push({
        key: "instrumentistSupplement", label: "Valoriser la prestation instrumentiste", tone: "primary", icon: "check", testId: "drawer-instrumentist-supplement",
        disabled: finance.supplementMutation.isPending, onClick: () => finance.supplementMutation.mutate(),
      });
    } else if (supplement && !supplement.obsolete && supplement.status === "CALCULATED") {
      footerActions.push({
        key: "approveSupplement", label: "Approuver la prestation instrumentiste", tone: "primary", icon: "check", testId: "drawer-approve-supplement",
        disabled: finance.approveMutation.isPending, onClick: () => finance.approveMutation.mutate(supplement.id),
      });
    }
  }

  const executed = financeApplicable;
  const footerTitle = !mission ? "" : executed ? "Encodage validé" : missionStatusLabel(status, mission.encoding?.state);
  const footerSub = [view && executed ? financeLabel(view) : null, mission?.encodingCorrectionBlockedReason ?? null]
    .filter(Boolean)
    .join(" · ") || undefined;

  const notices = mission ? (
    <>
      {/* D-125 — demande nominative : jamais présentée comme une mission couverte. */}
      {mission.targetedOffer?.status === "PENDING" && (
        <p className="mdw-notice mdw-notice--info">
          En attente de la réponse de <strong>{mission.targetedOffer.instrumentist?.name ?? "l'instrumentiste"}</strong> — la mission n'est pas encore attribuée.
        </p>
      )}
      {mission.targetedOffer?.status === "DECLINED" && (
        <p className="mdw-notice mdw-notice--warn">
          <strong>{mission.targetedOffer.instrumentist?.name ?? "L'instrumentiste"}</strong> a refusé la demande — la mission reste à couvrir.
        </p>
      )}
      <MissionEncodingOverviewNotice encoding={mission.encoding} />
      {mission.executedWithoutInstrumentist && (
        <p className="mdw-notice mdw-notice--muted" data-testid="manager-without-instrumentist">
          Activité réalisée sans instrumentiste
          {mission.executedWithoutInstrumentist.declaredByName ? ` — déclarée par ${mission.executedWithoutInstrumentist.declaredByName}` : ""}.
          Aucune heure ni prestation d'instrumentiste : seules les prestations applicables à la firme sont valorisées.
        </p>
      )}
      {status === "DECLARED" && (
        <p className="mdw-notice mdw-notice--warn">
          <span className="mdw-notice__title">En attente de validation</span>
          L'instrumentiste a déclaré cette mission. Vérifiez l'encodage et approuvez ou rejetez.
        </p>
      )}
      {/* D-171 — facturation firme indépendante des heures : la prestation horaire attend les heures réelles. */}
      {finance.active?.instrumentistHoursPending && !finance.supplement && (
        <p className="mdw-notice mdw-notice--info" data-testid="drawer-instrumentist-hours-pending">
          Calcul fait sans les heures prestées : les prestations firme sont facturables, la prestation de l'instrumentiste
          sera valorisée une fois ses heures déclarées (jamais sur les heures planifiées).
        </p>
      )}
      {finance.supplement && (
        <p className="mdw-notice mdw-notice--muted" data-testid="drawer-instrumentist-supplement-status">
          Prestation de l'instrumentiste calculée à part (complément v{finance.supplement.version}, {finance.supplement.status === "LOCKED" ? "verrouillé" : finance.supplement.status === "APPROVED" ? "approuvé" : "à approuver"}).
        </p>
      )}
      {mission.encodingCorrectionFinancialNotice && (
        <p className="mdw-notice mdw-notice--warn" data-testid="drawer-financial-notice">{mission.encodingCorrectionFinancialNotice}</p>
      )}
      {mission.submittedWithoutMaterial === true && mission.noMaterialComment && (
        <p className="mdw-notice mdw-notice--warn">
          <span className="mdw-notice__title">Aucun matériel encodé — justification de l'instrumentiste</span>
          <span className="mdw-notice__body">{mission.noMaterialComment}</span>
        </p>
      )}
    </>
  ) : null;

  return (
    <>
      <MissionDrawer
        open={open}
        missionId={id}
        view={view}
        placeholder={placeholder}
        statusPill={mission ? <MissionStatusChip status={mission.status} encodingState={mission.encoding?.state} /> : null}
        notices={notices}
        extra={mission ? <AnomalyReportsManagerPanel missionId={mission.id} /> : null}
        footer={{ title: footerTitle, sub: footerSub, actions: mission ? footerActions : [] }}
        corrections={mission ? { instrumentist: corrections.reassign, hours: corrections.editHours, encoding: corrections.editEncoding } : undefined}
        onClose={onClose}
      />
      {dialogs}
    </>
  );
}

export default ManagerMissionDrawer;
