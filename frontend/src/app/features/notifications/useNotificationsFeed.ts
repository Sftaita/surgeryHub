import { useInfiniteQuery, useQueryClient } from "@tanstack/react-query";
import {
  fetchNotifications,
  markAllNotificationsSeen,
  markNotificationSeen,
  type NotificationFilter,
} from "./api/notifications.api";

/**
 * Flux de notifications internes basé serveur (GET /api/notifications) — source de
 * vérité unique pour `seenAt`, cohérente entre appareils et entre écrans (manager,
 * admin, instrumentiste). Le cache localStorage historique (notifications.store.ts)
 * a été retiré (audit PWA/mobile/admin 2026-07-29, revue post-rapport) : le nudge
 * temps réel à la réception d'un push est désormais assuré par PushProvider (toast +
 * invalidation immédiate de la query ci-dessous), jamais par un état local persistant.
 *
 * Centre de notifications — `filter` (Toutes / Non lues / À traiter) est appliqué par le
 * serveur : « À traiter » dépend de l'état métier de la ressource, jamais de la lecture.
 * Pagination serveur (`offset` / `hasMore`) : les pages suivantes ne sont chargées qu'à la
 * demande (« Afficher plus »).
 */
export function useNotificationsFeed(limit = 50, filter: NotificationFilter = "all") {
  const qc = useQueryClient();
  const query = useInfiniteQuery({
    queryKey: ["notifications", "feed", limit, filter],
    queryFn: ({ pageParam }) => fetchNotifications(limit, filter, pageParam),
    initialPageParam: 0,
    getNextPageParam: (last, pages) => (last.hasMore ? pages.length * limit : undefined),
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

  const pages = query.data?.pages ?? [];
  // Compteurs : ceux de la page la plus récemment chargée (même instant serveur que la liste).
  const latest = pages[pages.length - 1];

  return {
    items: pages.flatMap((p) => p.items),
    unreadCount: latest?.unreadCount ?? 0,
    actionRequiredCount: latest?.actionRequiredCount ?? 0,
    hasMore: query.hasNextPage,
    loadMore: () => void query.fetchNextPage(),
    isLoadingMore: query.isFetchingNextPage,
    isLoading: query.isLoading,
    isError: query.isError,
    markSeen,
    markAllSeen,
  };
}
