import {
  Box, Button, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogTitle, Divider, Stack, Tooltip, Typography,
} from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { getInterventionTypeOfferings, type InterventionType } from "../api/interventionTypes.api";
import { FirmAvatar } from "../../manager-catalogue/components/FirmAvatar";
import { ActiveBadge } from "../../../ui/StatusBadge";
import { EmptyState } from "../../../ui/EmptyState";
import { formatOfferingForfait, hasPricingConflict, pricingConflictTooltip } from "../../billing-shared/pricingStatus";

/**
 * Catalogue > Prestations, refonte UX — détail d'une intervention globale (écran 14,
 * docs/design/screens/catalogue-prestations/README.md). Contexte strictement GLOBAL :
 * aucun logo de firme sélectionnée n'apparaît ici, seule la liste des firmes
 * UTILISATRICES avec leur propre forfait, jamais éditable depuis cet écran.
 */
export function InterventionTypeDetailDialog({
  open, onClose, intervention, onOpenFirm,
}: {
  open: boolean;
  onClose: () => void;
  intervention: InterventionType | null;
  /** Bascule vers le contexte FIRME (écran 3) sur la prestation exacte de cette firme. */
  onOpenFirm: (firmId: number, offeringId: number) => void;
}) {
  const offeringsQuery = useQuery({
    queryKey: ["intervention-type-offerings", intervention?.id],
    queryFn: () => getInterventionTypeOfferings(intervention!.id),
    enabled: open && !!intervention,
  });

  if (!intervention) return null;

  const offerings = offeringsQuery.data ?? [];

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth PaperProps={{ sx: { borderRadius: 3 } }}>
      <DialogTitle fontWeight={700}>
        {intervention.label}
        <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1, fontFamily: "monospace" }}>
          {intervention.code}
        </Typography>
      </DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <Stack direction="row" alignItems="center" spacing={1.5}>
            <Typography variant="overline" color="text.secondary">Identité clinique</Typography>
            <ActiveBadge active={intervention.active} />
          </Stack>

          <Divider />

          <Typography variant="overline" color="text.secondary">
            Utilisée par {offerings.length} firme{offerings.length !== 1 ? "s" : ""}
          </Typography>

          {offeringsQuery.isLoading ? (
            <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={22} /></Box>
          ) : offerings.length === 0 ? (
            <EmptyState
              variant="dashed"
              title="Aucune firme ne configure encore cette intervention."
            />
          ) : (
            <Stack spacing={1}>
              {offerings.map((row) => (
                <Stack
                  key={row.offeringId}
                  direction="row"
                  alignItems="center"
                  spacing={1.5}
                  sx={{ p: 1.25, border: "1px solid", borderColor: "grey.150", borderRadius: 2 }}
                >
                  <FirmAvatar name={row.firm.name} logoPath={row.firm.logoPath} size="sm" />
                  <Stack sx={{ flex: 1, minWidth: 0 }}>
                    <Typography variant="body2" fontWeight={600} noWrap>{row.firm.name}</Typography>
                    <Tooltip title={hasPricingConflict(row.pricingConflictRuleIds) ? pricingConflictTooltip(row.pricingConflictRuleIds) : ""}>
                      <Typography
                        variant="caption"
                        color={hasPricingConflict(row.pricingConflictRuleIds) ? "error.main" : "text.secondary"}
                        fontWeight={hasPricingConflict(row.pricingConflictRuleIds) ? 700 : 400}
                      >
                        {formatOfferingForfait(row)} {!row.active && "· inactive"}
                      </Typography>
                    </Tooltip>
                  </Stack>
                  <Button size="small" onClick={() => onOpenFirm(row.firm.id, row.offeringId)}>Ouvrir chez cette firme →</Button>
                </Stack>
              ))}
            </Stack>
          )}
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2.5 }}>
        <Button onClick={onClose} variant="contained" disableElevation>Fermer</Button>
      </DialogActions>
    </Dialog>
  );
}
