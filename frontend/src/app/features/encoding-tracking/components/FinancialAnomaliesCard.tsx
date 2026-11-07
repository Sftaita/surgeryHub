import * as React from "react";
import { Box, CircularProgress } from "@mui/material";
import { useMutation } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import type { MissionEncodingEntry } from "../../encoding/api/encoding.types";
import type { FinancialAnomaly, FinancialConflictingRule, FinancialTargetRule, MissionFinancialAnomalies } from "../api/encodingTracking.api";
import { calculateMission, recalculateFinancialCalculation } from "../../financial-calculation/api/financialCalculation.api";
import { ANOMALY_ACTION_ROUTES } from "../../financial-calculation/anomalyActionRoutes";
import { useToast } from "../../../ui/toast/useToast";

const GRAY_950 = "#0B1320";
const GRAY_700 = "#3A4754";
const GRAY_600 = "#5A6675";
const GRAY_500 = "#727E8C";
const GRAY_150 = "#E7EBEF";
const GREEN_800 = "#1F6B4F";
const GREEN_700 = "#2C7D5F";
const GREEN_100 = "#DDF4EA";
const RED_700 = "#C62F36";
const RED_100 = "#F8D7D8";
const RED_50 = "#FDEEEE";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

const CATEGORY_LABEL: Record<FinancialAnomaly["category"], string> = {
  CONFIGURATION: "Configuration tarifaire",
  ENCODING: "Encodage",
  TECHNICAL: "Erreur technique",
};

function formatDateTime(iso: string | null): string | null {
  if (!iso) return null;
  return new Date(iso).toLocaleString("fr-BE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

interface Props {
  missionId: number;
  data: MissionFinancialAnomalies | undefined;
  isLoading: boolean;
  isError: boolean;
  onRetryLoad: () => void;
  /** Lignes d'encodage chargées par le tiroir : seulement pour situer l'élément (« Intervention 3/7 »). */
  entries: MissionEncodingEntry[];
  onLocate: (target: { missionInterventionId: number | null; materialLineId: number | null }) => void;
  /** Après une relance (réussie ou non) : le tiroir recharge liste + détail depuis le backend. */
  onRecalculated: () => Promise<unknown>;
}

/**
 * D-138 — « Anomalies financières » du tiroir du suivi des encodages. Affiche TELLES
 * QUELLES les anomalies expliquées par le backend (GET .../financial-anomalies) : nature,
 * explication, firme / intervention / matériel concernés, action de résolution. Aucune
 * anomalie n'est déduite ici ; un échec de chargement s'affiche comme tel, jamais comme
 * une anomalie (ni comme une absence d'anomalie).
 *
 * « Relancer le calcul » réutilise les endpoints dédiés du moteur (calcul / recalcul) ; un
 * nouvel échec métier (422) recharge simplement le détail, qui reflète alors les anomalies
 * restantes.
 */
export function FinancialAnomaliesCard({ missionId, data, isLoading, isError, onRetryLoad, entries, onLocate, onRecalculated }: Props) {
  const toast = useToast();
  const navigate = useNavigate();

  const positions = React.useMemo(() => {
    const byIntervention = new Map<number, number>();
    const lineIds = new Set<number>();
    entries.forEach((entry, idx) => {
      if (entry.kind === "INTERVENTION") byIntervention.set(entry.id, idx + 1);
      entry.materialLines.forEach((l) => lineIds.add(l.id));
    });
    return { byIntervention, lineIds, total: entries.length };
  }, [entries]);

  const retryMutation = useMutation({
    mutationFn: async () => {
      const retry = data?.retry;
      if (!retry) throw new Error("Aucune relance possible.");
      return retry.kind === "RECALCULATE"
        ? recalculateFinancialCalculation(retry.calculationId)
        : calculateMission(missionId);
    },
    onSuccess: async () => {
      toast.success("Calcul financier effectué : plus aucune anomalie.");
      await onRecalculated();
    },
    onError: async (err: any) => {
      const apiError = err?.response?.data?.error;
      if (err?.response?.status === 422 && apiError?.code === "FINANCIAL_CALCULATION_ANOMALIES") {
        const n = Array.isArray(apiError.violations) ? apiError.violations.length : null;
        toast.error(n !== null ? `Le calcul échoue encore : ${n} anomalie${n > 1 ? "s" : ""} restante${n > 1 ? "s" : ""}.` : "Le calcul échoue encore.");
        await onRecalculated();
        return;
      }
      toast.error(err?.response?.status === 403 ? "Accès interdit." : apiError?.message ?? err?.message ?? "Le calcul n'a pas pu être relancé.");
    },
  });

  if (isLoading) {
    return (
      <Card>
        <Box sx={{ display: "flex", alignItems: "center", gap: "10px", fontSize: 12.5, color: GRAY_600 }}>
          <CircularProgress size={16} /> Chargement des anomalies financières…
        </Box>
      </Card>
    );
  }

  if (isError || !data) {
    return (
      <Card data-testid="financial-anomalies-error">
        <Eyebrow>ANOMALIES FINANCIÈRES</Eyebrow>
        <Box sx={{ fontSize: 12.5, fontWeight: 600, color: GRAY_700 }}>
          Impossible de charger le détail des anomalies financières. Le statut affiché dans la liste reste celui du serveur.
        </Box>
        <LinkButton onClick={onRetryLoad}>Réessayer</LinkButton>
      </Card>
    );
  }

  if (data.state !== "ANOMALY" || data.anomalies.length === 0) {
    return null;
  }

  const count = data.anomalies.length;
  const failedAt = formatDateTime(data.failedAt);
  const newAnomalies = data.newAnomalies ?? [];
  // D-141 — le pronostic vient du moteur (évaluation en lecture seule), jamais d'une déduction ici.
  const wouldSucceed = data.recalculation?.wouldSucceed === true;
  const remaining = data.recalculation?.remainingAnomalyCount ?? null;

  return (
    <Card data-testid="financial-anomalies" sx={{ borderColor: RED_100 }}>
      <Box sx={{ display: "flex", alignItems: "center", gap: "10px", flexWrap: "wrap" }}>
        <Box component="h3" sx={{ m: 0, fontSize: 13.5, fontWeight: 800, color: RED_700 }}>
          Anomalies financières — {count} problème{count > 1 ? "s" : ""} détecté{count > 1 ? "s" : ""}
        </Box>
        {data.retry && (
          <Box
            component="button"
            type="button"
            onClick={() => retryMutation.mutate()}
            disabled={retryMutation.isPending}
            sx={{
              ml: "auto", height: 28, padding: "0 10px", borderRadius: "8px", border: "1px solid", font: "inherit", fontSize: 12, fontWeight: 800, cursor: "pointer",
              borderColor: wouldSucceed ? GREEN_700 : GRAY_150, background: wouldSucceed ? GREEN_100 : "#fff", color: wouldSucceed ? GREEN_800 : GRAY_700,
            }}
          >
            {retryMutation.isPending ? "Calcul…" : "Relancer le calcul"}
          </Box>
        )}
      </Box>
      <Box sx={{ fontSize: 12, color: GRAY_500 }}>
        Le dernier calcul financier{failedAt ? ` (${failedAt})` : ""} a échoué : aucun montant n'a été enregistré.
        {wouldSucceed
          ? " Toutes les causes sont corrigées dans la configuration actuelle — relancez le calcul."
          : remaining !== null
            ? ` Un recalcul maintenant échouerait encore (${remaining} anomalie${remaining > 1 ? "s" : ""}) : corrigez les causes ci-dessous, puis relancez le calcul.`
            : " Corrigez les causes ci-dessous, puis relancez le calcul."}
      </Box>

      <AnomalyList anomalies={data.anomalies} positions={positions} onLocate={onLocate} onNavigate={(path) => navigate(path)} missionId={missionId} />

      {newAnomalies.length > 0 && (
        <>
          <Box data-testid="financial-new-anomalies" sx={{ fontSize: 12.5, fontWeight: 800, color: RED_700 }}>
            Apparue{newAnomalies.length > 1 ? "s" : ""} depuis le dernier calcul — {newAnomalies.length} anomalie{newAnomalies.length > 1 ? "s" : ""} qu'un recalcul produirait aussi
          </Box>
          <AnomalyList anomalies={newAnomalies} positions={positions} onLocate={onLocate} onNavigate={(path) => navigate(path)} missionId={missionId} numberOffset={count} />
        </>
      )}
    </Card>
  );
}

interface Positions { byIntervention: Map<number, number>; lineIds: Set<number>; total: number }

function AnomalyList({ anomalies, positions, onLocate, onNavigate, missionId, numberOffset = 0 }: {
  anomalies: FinancialAnomaly[];
  positions: Positions;
  onLocate: Props["onLocate"];
  onNavigate: (path: string) => void;
  missionId: number;
  numberOffset?: number;
}) {
  return (
    <Box component="ol" sx={{ m: 0, p: 0, listStyle: "none", display: "flex", flexDirection: "column", gap: "8px" }}>
      {anomalies.map((a, i) => {
        const idx = i + numberOffset;
        const itvPos = a.missionInterventionId !== null ? positions.byIntervention.get(a.missionInterventionId) : undefined;
        const locatable = (a.materialLineId !== null && positions.lineIds.has(a.materialLineId)) || itvPos !== undefined;
        const route = a.action ? ANOMALY_ACTION_ROUTES[a.action.code] : undefined;
        return (
          <Box
            component="li"
            key={`${a.code}:${idx}`}
            data-testid="financial-anomaly"
            data-anomaly-code={a.code}
            sx={{ padding: "10px 12px", borderRadius: "10px", background: a.resolved ? GRAY_150 : RED_50 }}
          >
            <Box sx={{ display: "flex", alignItems: "baseline", gap: "8px", flexWrap: "wrap" }}>
              <Box sx={{ fontSize: 13, fontWeight: 800, color: a.resolved ? GRAY_700 : RED_700 }}>{idx + 1}. {a.title}</Box>
              <Box sx={{ fontSize: 10.5, fontWeight: 700, color: GRAY_500 }}>{CATEGORY_LABEL[a.category]}</Box>
              {a.resolved && (
                <Box sx={{ fontSize: 10.5, fontWeight: 800, color: GREEN_800, background: GREEN_100, borderRadius: "999px", padding: "1px 7px" }}>
                  Corrigé — à recalculer
                </Box>
              )}
            </Box>
            <Box component="dl" sx={{ m: "4px 0 0", display: "grid", gridTemplateColumns: "auto 1fr", columnGap: "8px", rowGap: "1px", fontSize: 12 }}>
              {a.firm && <Detail term="Firme">{a.firm.name}</Detail>}
              {a.element?.type === "INTERVENTION" && (
                <Detail term="Intervention">
                  {a.element.label ?? "—"}{itvPos !== undefined ? ` (intervention ${itvPos}/${positions.total})` : ""}
                </Detail>
              )}
              {a.element?.type === "MATERIAL" && (
                <>
                  <Detail term="Matériel">
                    {a.element.label ?? "—"}{a.element.reference ? ` · Réf. ${a.element.reference}` : ""}
                  </Detail>
                  {itvPos !== undefined && <Detail term="Dans">{`intervention ${itvPos}/${positions.total}`}</Detail>}
                </>
              )}
              {a.element?.type === "INSTRUMENTIST" && <Detail term="Instrumentiste">{a.element.label ?? "—"}</Detail>}
            </Box>
            <Box sx={{ mt: "4px", fontSize: 12.5, color: GRAY_700 }}>{a.explanation}</Box>
            {(a.conflictingRules ?? []).length > 0 && <ConflictingRules rules={a.conflictingRules} />}
            {(a.targetRules ?? []).length > 0 && <ConflictingRules rules={a.targetRules} title="Tarifs existants (aucun ne couvre la date)" />}
            {a.currentResolution?.label && (
              <Box data-testid="financial-anomaly-current" sx={{ mt: "4px", fontSize: 12, fontWeight: 700, color: a.resolved ? GREEN_800 : GRAY_700 }}>
                Maintenant : {a.currentResolution.label}
              </Box>
            )}
            <Box sx={{ mt: "6px", display: "flex", gap: "14px", flexWrap: "wrap" }}>
              {locatable && (
                <LinkButton onClick={() => onLocate({ missionInterventionId: a.missionInterventionId, materialLineId: a.materialLineId })}>
                  Voir dans l'encodage
                </LinkButton>
              )}
              {a.action && !a.resolved && (route || a.action.code === "OPEN_MISSION") && (
                <LinkButton onClick={() => onNavigate(route ?? `/app/m/missions/${missionId}`)}>{a.action.label}</LinkButton>
              )}
            </Box>
          </Box>
        );
      })}
    </Box>
  );
}

/** Présentation seule des règles en conflit fournies par le backend (validTo exclusif). */
function formatRuleDay(day: string | null, exclusiveEnd = false): string | null {
  if (!day) return null;
  const d = new Date(`${day}T00:00:00`);
  if (exclusiveEnd) d.setDate(d.getDate() - 1);
  return d.toLocaleDateString("fr-BE", { day: "2-digit", month: "2-digit", year: "numeric" });
}

function ConflictingRules({ rules, title = "Règles en conflit" }: { rules: (FinancialConflictingRule & Partial<Pick<FinancialTargetRule, "active">>)[]; title?: string }) {
  return (
    <Box data-testid={title === "Règles en conflit" ? "conflicting-rules" : "target-rules"} sx={{ mt: "6px", borderLeft: `3px solid ${RED_100}`, paddingLeft: "8px", fontSize: 12, color: GRAY_700 }}>
      <Box sx={{ fontWeight: 800, color: GRAY_950 }}>{title}</Box>
      <Box component="ul" sx={{ m: 0, p: 0, listStyle: "none" }}>
        {rules.map((r, i) => {
          const from = formatRuleDay(r.validFrom);
          const to = formatRuleDay(r.validTo, true);
          return (
            <Box component="li" key={r.id ?? i} sx={{ fontVariantNumeric: "tabular-nums" }}>
              Règle #{r.id ?? "?"} — {r.unitPrice !== null ? `${Number(r.unitPrice).toLocaleString("fr-BE", { minimumFractionDigits: 2 })} ${r.currency ?? ""}` : "—"}
              {" · "}{from ? `du ${from}` : "sans date de début"}{to ? ` au ${to}` : ", sans date de fin"}{r.active === false ? " · désactivée" : ""}
            </Box>
          );
        })}
      </Box>
    </Box>
  );
}

function Detail({ term, children }: { term: string; children: React.ReactNode }) {
  return (
    <>
      <Box component="dt" sx={{ color: GRAY_500, fontWeight: 700 }}>{term}</Box>
      <Box component="dd" sx={{ m: 0, color: GRAY_950, fontWeight: 600, overflowWrap: "anywhere" }}>{children}</Box>
    </>
  );
}

function LinkButton({ onClick, children }: { onClick: () => void; children: React.ReactNode }) {
  return (
    <Box
      component="button"
      type="button"
      onClick={onClick}
      sx={{ border: 0, background: "none", font: "inherit", fontSize: 12, color: GREEN_700, fontWeight: 800, cursor: "pointer", p: 0 }}
    >
      {children}
    </Box>
  );
}

function Card({ children, sx, ...rest }: { children: React.ReactNode; sx?: object; "data-testid"?: string }) {
  return (
    <Box
      {...rest}
      sx={{
        background: "#fff", border: "1px solid", borderColor: GRAY_150, borderRadius: "14px", boxShadow: SHADOW_XS,
        padding: "14px 16px 16px", display: "flex", flexDirection: "column", gap: "10px", ...sx,
      }}
    >
      {children}
    </Box>
  );
}

function Eyebrow({ children }: { children: React.ReactNode }) {
  return <Box sx={{ fontSize: 11, fontWeight: 800, letterSpacing: ".09em", color: GRAY_500, whiteSpace: "nowrap" }}>{children}</Box>;
}

export default FinancialAnomaliesCard;
