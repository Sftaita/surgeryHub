import { NotificationCenter } from "../../features/notifications/NotificationCenter";

/**
 * Backé par GET /api/notifications, comme ManagerNotificationsPage — plus un cache
 * localStorage séparé (audit PWA/mobile/admin 2026-07-29, revue post-rapport). Le
 * backend est l'unique source de vérité : historique, compteur, lu/non lu, cohérents
 * entre appareils. Le nudge temps réel à la réception d'un push reste assuré par
 * PushProvider (toast + invalidation immédiate de la requête), pas par un état local
 * persistant ici.
 *
 * Centre de notifications — même composant que le manager (instrumentiste et chirurgien) ;
 * ouvrir l'écran ne marque plus tout comme lu.
 */
export default function NotificationsPage() {
  return <NotificationCenter />;
}
