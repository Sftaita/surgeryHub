import { Alert, Box, CircularProgress, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { getEncodingTrackingSummary, type EncodingTrackingFilter } from "../../encoding-tracking/api/encodingTracking.api";
import type { StatisticsFilter } from "../api/financialStatistics.api";

function toEncodingTrackingFilter(f: StatisticsFilter): EncodingTrackingFilter {
  return {
    from: f.from,
    to: f.to,
    siteId: f.siteId,
    surgeonId: f.surgeonId,
    instrumentistId: f.instrumentistId,
    firmId: f.firmId,
    interventionTypeId: f.interventionTypeId,
  };
}

/**
 * Étape 12 (D-118) — remplace le message plat "Aucune donnée financière sur cette
 * période/ces filtres" par une explication réelle, construite sur EncodingTrackingSummary
 * (même source canonique que le module Suivi des encodages — GET
 * /api/billing/encoding-tracking/summary). Aucune catégorie n'est inventée ici : seules
 * les valeurs déjà fournies par le backend sont affichées.
 *
 * Ne modifie jamais la sémantique du pipeline financier (D-077) : le pipeline reste
 * l'après-VALIDATED (`FinancialPipelineDto`, onglet Pipeline) ; ce breakdown ne couvre
 * que l'avant-VALIDATED, pour expliquer pourquoi rien n'est encore éligible.
 */
export function ZeroFinancialDataExplanation({ filter }: { filter: StatisticsFilter }) {
  const trackingFilter = toEncodingTrackingFilter(filter);
  const query = useQuery({
    queryKey: ["encoding-tracking", "summary", "explain-zero-financial-data", trackingFilter],
    queryFn: () => getEncodingTrackingSummary(trackingFilter),
  });

  if (query.isLoading) {
    return <Box sx={{ py: 1 }}><CircularProgress size={18} /></Box>;
  }

  // Dégradation gracieuse : si le résumé d'encodage échoue, on retombe sur le message
  // historique plutôt que de laisser la page en erreur pour un bloc secondaire.
  if (query.isError || !query.data) {
    return <Typography color="text.secondary">Aucune donnée financière sur cette période/ces filtres.</Typography>;
  }

  const s = query.data.summary;

  if (s.encodingExpected === 0) {
    return <Typography color="text.secondary">Aucune mission sur cette période/ces filtres ne nécessite d'encodage.</Typography>;
  }

  // Cas distinct du "rien n'est encodé" : des missions ont bien été validées, mais
  // aucun calcul financier n'a encore été lancé ou approuvé sur elles — c'est le
  // pipeline (D-077 §17) qui identifie précisément lesquelles, pas ce bloc.
  if (s.hasFinanciallyEligibleMissions) {
    return (
      <Alert severity="info">
        {s.validated + s.locked} mission(s) sont validées, mais leur calcul financier n'a pas
        encore été lancé ou approuvé — voir l'onglet <strong>Pipeline</strong> pour les identifier précisément.
      </Alert>
    );
  }

  return (
    <Alert severity="info">
      <Typography variant="body2" fontWeight={600} gutterBottom>
        {s.encodingExpected} mission(s) nécessitent un encodage sur cette période, mais aucune
        n'est actuellement éligible aux statistiques financières.
      </Typography>
      <Box component="ul" sx={{ pl: 2.5, m: 0 }}>
        {s.toEncode > 0 && <li><Typography variant="body2">{s.toEncode} à encoder</Typography></li>}
        {s.inProgress > 0 && <li><Typography variant="body2">{s.inProgress} en cours d'encodage</Typography></li>}
        {s.submitted > 0 && <li><Typography variant="body2">{s.submitted} soumise(s) non validée(s)</Typography></li>}
        {s.financialAnomalies > 0 && <li><Typography variant="body2">{s.financialAnomalies} anomalie(s) financière(s)</Typography></li>}
      </Box>
    </Alert>
  );
}

export default ZeroFinancialDataExplanation;
