import { Alert, Box, CircularProgress, Divider, Stack, Typography } from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  createInstrumentistRate,
  deleteInstrumentistRate,
  getInstrumentistRates,
  replaceInstrumentistRate,
  updateInstrumentistRate,
  type InstrumentistRateType,
} from "../api/instrumentistRate.api";
import RateVersionManager from "../../billing-shared/components/RateVersionManager";
import { useToast } from "../../../ui/toast/useToast";

function extractError(err: unknown): string {
  const e = err as any;
  const violations = e?.response?.data?.error?.violations;
  if (Array.isArray(violations) && violations.length > 0) {
    return violations.map((v: any) => v.message ?? JSON.stringify(v)).join(" · ");
  }
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

interface Props {
  instrumentistId: number;
}

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — tarifs historisés utilisés par
 * FinancialCalculationService (InstrumentistRateResolver). Sans HOURLY_RATE actif ici,
 * "Calculer la valorisation" sur la fiche mission échoue pour cet instrumentiste.
 */
export default function InstrumentistVersionedRates({ instrumentistId }: Props) {
  const toast = useToast();
  const qc = useQueryClient();

  const query = useQuery({
    queryKey: ["instrumentist-rates", instrumentistId],
    queryFn: () => getInstrumentistRates(instrumentistId),
    enabled: Number.isFinite(instrumentistId),
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ["instrumentist-rates", instrumentistId] });
    // Diagnostic tarifs instrumentistes (2026-08-05) — sans ceci, le badge « Tarif
    // manquant »/« Tarif configuré » de la liste manager reste périmé après une
    // création/remplacement/modification/annulation ici (clé de requête distincte,
    // découverte lors du test réel en local).
    qc.invalidateQueries({ queryKey: ["instrumentists"] });
  };

  const createMutation = useMutation({
    mutationFn: (body: { rateType: InstrumentistRateType; amount: number; currency: string; validFrom: string | null; validTo: string | null }) =>
      createInstrumentistRate(instrumentistId, body),
    onSuccess: () => { toast.success("Tarif créé"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const replaceMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: { amount: number; currency: string; effectiveFrom: string } }) =>
      replaceInstrumentistRate(instrumentistId, id, body),
    onSuccess: () => { toast.success("Tarif remplacé à partir de la date choisie"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const editMutation = useMutation({
    mutationFn: ({ id, body }: { id: number; body: { amount?: number; currency?: string; validFrom?: string | null; validTo?: string | null } }) =>
      updateInstrumentistRate(instrumentistId, id, body),
    onSuccess: () => { toast.success("Tarif programmé modifié"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });
  const cancelMutation = useMutation({
    mutationFn: (id: number) => deleteInstrumentistRate(instrumentistId, id),
    onSuccess: () => { toast.success("Tarif programmé annulé"); invalidate(); },
    onError: (e) => toast.error(extractError(e)),
  });

  const isSaving = createMutation.isPending || replaceMutation.isPending || editMutation.isPending || cancelMutation.isPending;

  if (query.isLoading) return <CircularProgress size={20} />;

  const rates = query.data ?? [];
  const hourly = rates.filter((r) => r.rateType === "HOURLY_RATE");
  const consultation = rates.filter((r) => r.rateType === "CONSULTATION_FEE");

  // Diagnostic tarifs instrumentistes (2026-08-05) — même sémantique que
  // InstrumentistRateResolver::coversDate() (validFrom inclusif, validTo exclusif,
  // null = ouvert). Purement indicatif : les versions listées ci-dessous restent la
  // vérité affichée, ceci ne fait que résumer si l'une d'elles couvre aujourd'hui.
  const todayIso = new Date().toISOString().slice(0, 10);
  const hasActiveHourlyToday = hourly.some(
    (r) => r.validFrom !== null && r.validFrom <= todayIso && (r.validTo === null || todayIso < r.validTo),
  );

  return (
    <Stack spacing={2}>
      <Alert severity="info" variant="outlined">
        Ces tarifs historisés alimentent le <strong>calcul financier</strong> des missions (EPIC Exécution &amp;
        Valorisation, Lot 3) — indépendants des champs "Tarif bloc" / "Tarif consultation" ci-dessus, qui ne
        servent qu'à l'ancien flux de génération de décompte. Sans tarif horaire actif ici, la valorisation
        d'une mission de cet instrumentiste échouera.
      </Alert>

      <Box>
        <Typography variant="subtitle2" fontWeight={700} mb={1}>Tarif horaire (bloc)</Typography>
        {!hasActiveHourlyToday && (
          <Alert severity="warning" variant="outlined" sx={{ mb: 1.5 }}>
            <strong>Aucun tarif horaire actif.</strong> Les missions de cet instrumentiste ne pourront pas être
            valorisées tant qu'un tarif horaire n'est pas configuré ci-dessous.
          </Alert>
        )}
        <RateVersionManager
          versions={hourly.map((r) => ({ id: r.id, amount: r.amount, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo }))}
          onCreateFirst={(b) => createMutation.mutate({ rateType: "HOURLY_RATE", ...b })}
          onReplaceActive={(id, b) => replaceMutation.mutate({ id, body: b })}
          onEditFuture={(id, b) => editMutation.mutate({ id, body: b })}
          onCancelFuture={(id) => cancelMutation.mutate(id)}
          isSaving={isSaving}
        />
      </Box>

      <Divider />

      <Box>
        <Typography variant="subtitle2" fontWeight={700} mb={1}>Tarif consultation</Typography>
        <RateVersionManager
          versions={consultation.map((r) => ({ id: r.id, amount: r.amount, currency: r.currency, validFrom: r.validFrom, validTo: r.validTo }))}
          onCreateFirst={(b) => createMutation.mutate({ rateType: "CONSULTATION_FEE", ...b })}
          onReplaceActive={(id, b) => replaceMutation.mutate({ id, body: b })}
          onEditFuture={(id, b) => editMutation.mutate({ id, body: b })}
          onCancelFuture={(id) => cancelMutation.mutate(id)}
          isSaving={isSaving}
        />
      </Box>
    </Stack>
  );
}
