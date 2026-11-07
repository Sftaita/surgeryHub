import { Box } from "@mui/material";
import NotificationsActiveOutlinedIcon from "@mui/icons-material/NotificationsActiveOutlined";

import { NotificationCenter } from "../../features/notifications/NotificationCenter";
import { PageHeader } from "../../ui/PageHeader";

/**
 * Historique des notifications internes pour manager/admin — absent avant
 * (audit PWA/mobile/admin 2026-07-29, Lot 3) : seul l'instrumentiste avait
 * une cloche + un historique. Backé par GET /api/notifications (serveur,
 * pas un cache local), donc cohérent entre appareils.
 *
 * Centre de notifications — texte, état métier et cible viennent du serveur
 * (NotificationCenter) ; ouvrir l'écran ne marque plus tout comme lu.
 */
export default function ManagerNotificationsPage() {
  return (
    <Box sx={{ maxWidth: 760, mx: "auto" }}>
      <PageHeader
        icon={NotificationsActiveOutlinedIcon}
        title="Notifications"
        subtitle="Ce qui s'est passé sur vos missions, absences et demandes — et ce qui attend une intervention."
      />
      <NotificationCenter />
    </Box>
  );
}
