import { Alert, Stack, Typography } from "@mui/material";
import type { EligibleLinesDiagnostic } from "../../billing-firm/api/firmInvoice.api";

/**
 * D-121, §6 — traduit les codes stables renvoyés par le backend
 * (previewEligibleLines()/diagnostic) en phrases claires. Aucune déduction métier ici :
 * uniquement du texte, une phrase par raison présente dans `reasons`.
 */
function describeReason(code: EligibleLinesDiagnostic["reasons"][number], d: EligibleLinesDiagnostic): string {
  switch (code) {
    case "NO_VALIDATED_MISSIONS":
      return "Aucune mission n'est validée sur cette période — l'encodage doit d'abord être revu et validé (écran \"Suivi des encodages\") avant qu'un calcul financier puisse être créé.";
    case "NO_FINANCIAL_CALCULATIONS":
      return `${d.validatedMissionCount} mission(s) validée(s) sur cette période, mais aucun calcul financier n'a encore été lancé — voir la carte "Calcul financier" sur chaque fiche mission.`;
    case "CALCULATIONS_PENDING_APPROVAL":
      return `${d.calculatedCount} calcul(s) financier(s) existent mais sont encore en attente d'approbation.`;
    case "MISSING_PRICING":
      return `${d.missingPricingCount} mission(s) n'ont pas pu être calculées faute de tarif configuré (règle tarifaire ou taux instrumentiste manquant) — voir la configuration des tarifs.`;
    case "CURRENCY_MISMATCH":
      return `${d.currencyMismatchCount} ligne(s) existent mais dans une autre devise que celle sélectionnée.`;
    case "ALREADY_INVOICED":
      return "Toutes les lignes de cette période ont déjà été facturées.";
    case "NO_LINES_FOR_BENEFICIARY":
      return "Un calcul financier existe pour cette période, mais aucun montant n'est dû (décision commerciale : forfait non applicable).";
    default:
      return code;
  }
}

export default function EligibleLinesEmptyState({ diagnostic }: { diagnostic?: EligibleLinesDiagnostic }) {
  if (!diagnostic) {
    return <Typography color="text.secondary">Aucune ligne éligible pour cette période.</Typography>;
  }

  return (
    <Stack spacing={1}>
      <Typography color="text.secondary" fontWeight={600}>Aucune ligne éligible pour cette période.</Typography>
      {diagnostic.reasons.map((code) => (
        <Alert key={code} severity="info" variant="outlined">
          {describeReason(code, diagnostic)}
        </Alert>
      ))}
    </Stack>
  );
}
