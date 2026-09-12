import { Alert, Box, CircularProgress, Stack, Typography } from "@mui/material";
import type { EncodingTrackingItem } from "../api/encodingTracking.api";
import { EncodingTrackingTable } from "./EncodingTrackingTable";
import { EmptyState } from "../../../ui/EmptyState";

interface Props {
  items: EncodingTrackingItem[];
  isLoading: boolean;
  isError: boolean;
  /** true quand `total` dépasse le nombre d'items chargés (plafond de la vue large). */
  isCapped: boolean;
  cappedTotal: number;
}

/**
 * Suivi des encodages (D-118), Étape 8 — vue "À traiter".
 *
 * Chaque section filtre `items` sur des champs DÉJÀ résolus par le backend
 * (encodingState, encoding.isStale, financial.state) : aucune règle métier n'est
 * recomposée ici, seulement un tri d'affichage sur des valeurs canoniques.
 *
 * Les catégories ne sont pas mutuellement exclusives par construction (ex. une mission
 * SUBMITTED peut aussi porter une anomalie financière résiduelle d'un calcul antérieur) —
 * chacune représente une action de suivi différente, pas une partition.
 */
export function ToTreatPanel({ items, isLoading, isError, isCapped, cappedTotal }: Props) {
  if (isLoading) {
    return (
      <Box sx={{ p: 4, textAlign: "center" }}>
        <CircularProgress size={24} />
      </Box>
    );
  }

  if (isError) {
    return (
      <EmptyState
        title="Impossible de charger la vue « À traiter »"
        description="Une erreur est survenue lors de la récupération des données. Réessayez dans un instant."
      />
    );
  }

  const missing = items.filter((i) => i.encodingState === "TO_ENCODE");
  const stale = items.filter((i) => i.encoding.isStale);
  const toValidate = items.filter((i) => i.encodingState === "SUBMITTED");
  const anomalies = items.filter((i) => i.financial.isBlocking);

  const nothingToTreat = missing.length === 0 && stale.length === 0 && toValidate.length === 0 && anomalies.length === 0;

  if (nothingToTreat) {
    return (
      <EmptyState
        title="Rien à traiter sur cette période"
        description="Aucun encodage manquant, aucune soumission en attente de validation, aucune anomalie financière."
      />
    );
  }

  return (
    <Stack spacing={3}>
      {isCapped && (
        <Alert severity="info">
          Cette vue est calculée sur les {items.length} premières missions de la période
          (sur {cappedTotal} au total) — affinez les filtres ou réduisez la période pour une vue exhaustive.
        </Alert>
      )}

      <Section title="Encodages manquants" items={missing} emptyTitle="Aucun encodage manquant" />
      <Section title="En cours anormalement longtemps" items={stale} emptyTitle="Aucun encodage en cours en retard" />
      <Section title="À valider" items={toValidate} emptyTitle="Aucune soumission en attente" />
      <Section title="Anomalies financières" items={anomalies} emptyTitle="Aucune anomalie financière" />
    </Stack>
  );
}

function Section({ title, items, emptyTitle }: { title: string; items: EncodingTrackingItem[]; emptyTitle: string }) {
  if (items.length === 0) return null;

  return (
    <Box>
      <Typography variant="subtitle2" fontWeight={700} mb={1}>{title} — {items.length}</Typography>
      <EncodingTrackingTable
        items={items}
        total={items.length}
        page={1}
        limit={items.length}
        isLoading={false}
        isError={false}
        onPageChange={() => {}}
        emptyTitle={emptyTitle}
      />
    </Box>
  );
}

export default ToTreatPanel;
