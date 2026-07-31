import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  fetchNotifications,
  markAllNotificationsSeen,
  markNotificationSeen,
} from "./api/notifications.api";

/**
 * Flux de notifications internes basé serveur (GET /api/notifications) — source de
 * vérité unique pour `seenAt`, cohérente entre appareils et entre écrans (manager,
 * admin, instrumentiste). Le cache localStorage historique (notifications.store.ts)
 * a été retiré (audit PWA/mobile/admin 2026-07-29, revue post-rapport) : le nudge
 * temps réel à la réception d'un push est désormais assuré par PushProvider (toast +
 * invalidation immédiate de la query ci-dessous), jamais par un état local persistant.
 */
export function useNotificationsFeed(limit = 50) {
  const qc = useQueryClient();
  const query = useQuery({
    queryKey: ["notifications", "feed", limit],
    queryFn: () => fetchNotifications(limit),
    refetchInterval: 60_000,
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ["notifications"] });

  const markSeen = async (id: number) => {
    await markNotificationSeen(id);
    await invalidate();
  };

  const markAllSeen = async () => {
    await markAllNotificationsSeen();
    await invalidate();
  };

  return {
    items: query.data?.items ?? [],
    unreadCount: query.data?.unreadCount ?? 0,
    isLoading: query.isLoading,
    isError: query.isError,
    markSeen,
    markAllSeen,
  };
}
