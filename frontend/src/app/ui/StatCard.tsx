import { Box, Chip, Paper, Stack, SvgIconTypeMap, Typography } from "@mui/material";
import { OverridableComponent } from "@mui/material/OverridableComponent";

type StatCardProps = {
  label: string;
  value: string | number;
  /** Puce affichée si non vide — ex. "À traiter" quand value > 0. */
  hint?: string;
  hintColor?: "default" | "warning" | "success" | "error" | "info";
  /** Couleur du thème MUI appliquée à la valeur (ex. "warning.main", "success.main"). */
  color?: string;
  icon?: OverridableComponent<SvgIconTypeMap>;
  onClick?: () => void;
};

/**
 * Carte KPI générique — label + grande valeur + puce optionnelle. Informée
 * par les deux implémentations locales de FinancialStatisticsPage.tsx
 * (`Metric()` et la carte grille de `PipelineTab`), n'existait nulle part
 * en composant partagé avant. Base du Dashboard, réutilisable partout
 * ailleurs qu'un chiffre-clé doit s'afficher.
 */
export function StatCard({ label, value, hint, hintColor = "warning", color, icon: Icon, onClick }: StatCardProps) {
  return (
    <Paper
      variant="outlined"
      onClick={onClick}
      sx={{
        p: 2,
        borderRadius: 2,
        cursor: onClick ? "pointer" : undefined,
        transition: "border-color 150ms, box-shadow 150ms",
        "&:hover": onClick ? { borderColor: "primary.main", boxShadow: 1 } : undefined,
      }}
    >
      <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={1}>
        <Box sx={{ minWidth: 0 }}>
          <Typography variant="caption" color="text.secondary" noWrap>
            {label}
          </Typography>
          <Typography variant="h4" fontWeight={700} color={color} sx={{ mt: 0.25 }}>
            {value}
          </Typography>
        </Box>
        {Icon && <Icon sx={{ color: "text.disabled", fontSize: 22 }} />}
      </Stack>
      {hint && (
        <Chip size="small" label={hint} variant="outlined" color={hintColor} sx={{ mt: 1 }} />
      )}
    </Paper>
  );
}

export default StatCard;
