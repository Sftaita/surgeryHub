import { Box } from "@mui/material";
import { StatCard } from "../../../ui/StatCard";
import type { EncodingTrackingSummary } from "../api/encodingTracking.api";

interface Props {
  summary: EncodingTrackingSummary | undefined;
  isLoading: boolean;
  onSelectToEncode?: () => void;
  onSelectInProgress?: () => void;
  onSelectSubmitted?: () => void;
  onSelectAnomalies?: () => void;
}

/**
 * Suivi des encodages (D-118) — KPI de la période, lus tels quels depuis
 * EncodingTrackingSummary (backend). Aucun total n'est recalculé ici à partir de `items` :
 * `encoded`, `missingEncoding` et `toTreat` sont déjà fournis par le backend précisément
 * pour que le badge de navigation et cette rangée ne puissent jamais diverger.
 *
 * "Missions" et "Terminées" ne sont pas des champs nommés du contrat — ce sont de
 * simples soustractions entre deux totaux DÉJÀ exhaustifs et canoniques du résumé
 * (`totalMissions`, `upcoming`), jamais un nouveau parcours de `items` (qui, lui, est
 * paginé et pourrait diverger). "Missions" = totalMissions (tout, y compris annulées —
 * ce qu'un manager attend en comparant à son planning), volontairement PAS
 * `encodingExpected` qui exclut silencieusement les missions hors cycle d'encodage.
 */
export function EncodingKpiRow({ summary, isLoading, onSelectToEncode, onSelectInProgress, onSelectSubmitted, onSelectAnomalies }: Props) {
  const v = (n: number | undefined) => (isLoading || n === undefined ? "—" : String(n));

  return (
    <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(140px, 1fr))", gap: 1.5 }}>
      <StatCard label="Missions" value={v(summary?.totalMissions)} />
      <StatCard label="Terminées" value={v(summary && summary.totalMissions - summary.upcoming)} />
      <StatCard
        label="À encoder"
        value={v(summary?.toEncode)}
        color={summary && summary.toEncode > 0 ? "error.main" : undefined}
        onClick={onSelectToEncode}
      />
      <StatCard
        label="En cours"
        value={v(summary?.inProgress)}
        color={summary && summary.staleInProgress > 0 ? "warning.main" : undefined}
        hint={summary && summary.staleInProgress > 0 ? `${summary.staleInProgress} en retard` : undefined}
        onClick={onSelectInProgress}
      />
      {/*
        Une seule carte pour "Soumises" et "À valider" : sous ce modèle backend,
        SUBMITTED signifie exactement "action manager de validation attendue" — les
        compter deux fois sous deux libellés produirait deux cartes strictement
        identiques (cf. UX : "éviter les cartes redondantes").
      */}
      <StatCard
        label="Soumises"
        value={v(summary?.submitted)}
        color={summary && summary.submitted > 0 ? "info.main" : undefined}
        hint={summary && summary.submitted > 0 ? "à valider" : undefined}
        hintColor="info"
        onClick={onSelectSubmitted}
      />
      <StatCard
        label="Anomalies"
        value={v(summary?.financialAnomalies)}
        color={summary && summary.financialAnomalies > 0 ? "error.main" : undefined}
        onClick={onSelectAnomalies}
      />
    </Box>
  );
}

export default EncodingKpiRow;
