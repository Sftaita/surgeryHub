import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  approveFinancialCalculation,
  calculateMission,
  lockFinancialCalculation,
  listMissionCalculations,
  recalculateFinancialCalculation,
} from "../api/financialCalculation.api";
import { useToast } from "../../../ui/toast/useToast";

function extractFinancialError(err: unknown): string {
  const e = err as any;
  const violations = e?.response?.data?.error?.violations;
  if (Array.isArray(violations) && violations.length > 0) {
    return violations.map((v: any) => v.message ?? JSON.stringify(v)).join(" · ");
  }
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

/**
 * Calcul financier d'une mission (D-073) : versions, calcul actif et transitions
 * calculer / recalculer / approuver / verrouiller — chacune par son endpoint dédié.
 * Utilisé par le tiroir mission manager (ManagerMissionDrawer).
 */
export function useMissionFinancialCalculation(missionId: number, { enabled = true }: { enabled?: boolean } = {}) {
  const toast = useToast();
  const qc = useQueryClient();

  const query = useQuery({
    queryKey: ["mission-financial-calculations", missionId],
    queryFn: () => listMissionCalculations(missionId),
    enabled: enabled && Number.isFinite(missionId),
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ["mission-financial-calculations", missionId] });

  const calculateMutation = useMutation({
    mutationFn: () => calculateMission(missionId),
    onSuccess: () => { toast.success("Calcul financier créé"); invalidate(); },
    onError: (err) => toast.error(extractFinancialError(err)),
  });

  const recalculateMutation = useMutation({
    mutationFn: (id: number) => recalculateFinancialCalculation(id),
    onSuccess: () => { toast.success("Nouvelle version calculée"); invalidate(); },
    onError: (err) => toast.error(extractFinancialError(err)),
  });

  const approveMutation = useMutation({
    mutationFn: (id: number) => approveFinancialCalculation(id),
    onSuccess: () => { toast.success("Calcul approuvé"); invalidate(); },
    onError: (err) => toast.error(extractFinancialError(err)),
  });

  const lockMutation = useMutation({
    mutationFn: (id: number) => lockFinancialCalculation(id),
    onSuccess: () => { toast.success("Calcul verrouillé — ses lignes sont maintenant disponibles pour la facturation"); invalidate(); },
    onError: (err) => toast.error(extractFinancialError(err)),
  });

  const calculations = query.data ?? [];
  const active = calculations.find((c) => c.status === "CALCULATED" || c.status === "APPROVED" || c.status === "LOCKED");
  const history = calculations.filter((c) => c.id !== active?.id).sort((a, b) => b.version - a.version);

  return { query, active, history, calculateMutation, recalculateMutation, approveMutation, lockMutation };
}
