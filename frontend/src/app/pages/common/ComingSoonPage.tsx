import { Box } from "@mui/material";
import HourglassEmptyOutlinedIcon from "@mui/icons-material/HourglassEmptyOutlined";
import { EmptyState } from "../../ui/EmptyState";

/**
 * Repli propre pour une route déjà câblée (guard, navigation) mais dont
 * l'écran réel n'est pas encore livré — jamais un composant vide/cassé, jamais
 * une fausse donnée métier. Chaque lot futur (Planning chirurgien, Activité,
 * Demandes, Absences...) remplace l'appel à ce composant par sa page réelle
 * sans toucher au routing/guard déjà en place (socle mobile Lot 1, 2026-08-05).
 */
export default function ComingSoonPage({ title }: { title: string }) {
  return (
    <Box sx={{ pt: 4 }}>
      <EmptyState
        icon={HourglassEmptyOutlinedIcon}
        title={`${title} — bientôt disponible`}
        description="Cet écran arrive dans une prochaine mise à jour."
      />
    </Box>
  );
}
