import { Button, Stack, SvgIconTypeMap, Typography } from "@mui/material";
import { OverridableComponent } from "@mui/material/OverridableComponent";

type EmptyStateProps = {
  title: string;
  description?: string;
  icon?: OverridableComponent<SvgIconTypeMap>;
  action?: { label: string; onClick: () => void };
  /** Style "dashed panel" utilisé sur certaines pages (ex. FirmsPage) plutôt que le simple bloc centré. */
  variant?: "plain" | "dashed";
};

/**
 * Bloc "aucun résultat" générique — remplace les implémentations locales
 * quasi identiques dupliquées page par page (InstrumentistsTable,
 * SurgeonsTable, FirmsPage, HospitalsPage, InterventionTypesPage,
 * FinancialStatisticsPage...).
 */
export function EmptyState({ title, description, icon: Icon, action, variant = "plain" }: EmptyStateProps) {
  return (
    <Stack
      alignItems="center"
      justifyContent="center"
      spacing={1}
      sx={
        variant === "dashed"
          ? { py: 8, px: 2, border: "1px dashed", borderColor: "divider", borderRadius: 2, textAlign: "center" }
          : { py: 6, px: 2, minHeight: 220, textAlign: "center" }
      }
    >
      {Icon && <Icon sx={{ fontSize: 48, color: "text.disabled", mb: 1 }} />}
      <Typography variant="subtitle1" color={variant === "dashed" ? "text.secondary" : "text.primary"}>
        {title}
      </Typography>
      {description && (
        <Typography variant="body2" color="text.secondary" textAlign="center">
          {description}
        </Typography>
      )}
      {action && (
        <Button onClick={action.onClick} sx={{ mt: 1 }} variant={variant === "dashed" ? "contained" : "text"} disableElevation>
          {action.label}
        </Button>
      )}
    </Stack>
  );
}

export default EmptyState;
