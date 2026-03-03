import * as React from "react";
import {
  Box,
  Paper,
  Stack,
  Typography,
  ToggleButton,
  ToggleButtonGroup,
} from "@mui/material";

type TopToggle = "my-missions" | "offers";
type ViewMode = "month" | "week";

export default function PlanningPage() {
  // P1A: UI only, valeurs par défaut
  const [top, setTop] = React.useState<TopToggle>("my-missions");
  const [view, setView] = React.useState<ViewMode>("month");

  return (
    <Stack spacing={2}>
      <Typography variant="h6">Planning</Typography>

      {/* Toggle horizontal : Mes missions / Offres */}
      <ToggleButtonGroup
        value={top}
        exclusive
        onChange={(_, v: TopToggle | null) => {
          if (!v) return;
          setTop(v);
        }}
        fullWidth
        size="small"
        aria-label="Planning: Mes missions ou Offres"
      >
        <ToggleButton value="my-missions" aria-label="Mes missions">
          Mes missions
        </ToggleButton>
        <ToggleButton value="offers" aria-label="Offres">
          Offres
        </ToggleButton>
      </ToggleButtonGroup>

      {/* Switch de vue : Mois (défaut) / Semaine */}
      <ToggleButtonGroup
        value={view}
        exclusive
        onChange={(_, v: ViewMode | null) => {
          if (!v) return;
          setView(v);
        }}
        fullWidth
        size="small"
        aria-label="Planning: vue Mois ou Semaine"
      >
        <ToggleButton value="month" aria-label="Mois">
          Mois
        </ToggleButton>
        <ToggleButton value="week" aria-label="Semaine">
          Semaine
        </ToggleButton>
      </ToggleButtonGroup>

      {/* Bloc Résumé du mois (UI statique uniquement) */}
      <Paper variant="outlined" sx={{ p: 2 }}>
        <Stack spacing={0.5}>
          <Typography variant="subtitle2">Résumé du mois</Typography>
          <Typography variant="body2">Missions : —</Typography>
          <Typography variant="body2">Heures : —</Typography>
        </Stack>
      </Paper>

      {/* Zone contenu : placeholders */}
      <Paper variant="outlined" sx={{ p: 2 }}>
        <Box>
          {view === "month" ? (
            <Typography variant="body2">
              Placeholder — Vue Mois (calendrier non implémenté dans P1A)
            </Typography>
          ) : (
            <Typography variant="body2">
              Placeholder — Vue Semaine (calendrier non implémenté dans P1A)
            </Typography>
          )}
        </Box>

        {/* P1A: affichage du toggle haut sans logique métier */}
        <Box sx={{ mt: 1 }}>
          <Typography variant="caption" color="text.secondary">
            Mode sélectionné :{" "}
            {top === "my-missions" ? "Mes missions" : "Offres"}
          </Typography>
        </Box>
      </Paper>
    </Stack>
  );
}
