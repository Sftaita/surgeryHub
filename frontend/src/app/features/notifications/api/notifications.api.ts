import { apiClient } from "../../../api/apiClient";

/** Centre de notifications — famille d'icône, choisie par le serveur. */
export type NotificationCategory =
  | "mission" | "offer" | "absence" | "planning" | "alert" | "encoding" | "catalogue" | "request" | "room" | "info";

/**
 * État métier, indépendant de la lecture (`seenAt`) : lu sur la ressource actuelle par le
 * serveur (NotificationPresenter). Lire une notification ne la « traite » jamais.
 */
export type NotificationBusinessState = "INFO" | "ACTION_REQUIRED" | "DONE";

/** Une mission concernée par une notification (impact d'absence), telle que décrite par le serveur. */
export type NotificationMission = {
  missionId: number;
  dateLabel: string | null;
  timeLabel: string | null;
  siteName: string | null;
  surgeonName: string | null;
  impactLabel: string | null;
  statusLabel: string;
  covered: boolean;
  targetUrl: string;
};

export type NotificationItem = {
  id: number;
  eventType: string;
  missionId: number | null;
  /** Titre et phrase rédigés côté serveur — jamais reconstruits ici à partir du payload. */
  title: string;
  body: string;
  category: NotificationCategory;
  /** Libellé de l'action ouverte par le clic ; null quand la notification ne mène nulle part. */
  actionLabel: string | null;
  businessState: NotificationBusinessState;
  stateLabel: string | null;
  /** Ancienne notification dont le contexte n'a pas été conservé (affiché tel quel, sans invention). */
  legacy: boolean;
  missions: NotificationMission[] | null;
  /**
   * Point 4 (audit UX) — cible de navigation calculée côté serveur
   * (NotificationTargetResolver), jamais reconstruite côté frontend à partir de
   * missionId/eventType. `null` = notification non actionnable (purement
   * informative, ou pas de cible connue pour ce type).
   */
  targetUrl: string | null;
  payload: Record<string, unknown> | null;
  sentAt: string | null;
  seenAt: string | null;
};

export type NotificationFilter = "all" | "unread" | "action";

export type NotificationsListResponse = {
  items: NotificationItem[];
  /** Une page suivante existe (pagination serveur `offset`). */
  hasMore: boolean;
  unreadCount: number;
  actionRequiredCount: number;
};

export async function fetchNotifications(limit = 50, filter: NotificationFilter = "all", offset = 0): Promise<NotificationsListResponse> {
  const { data } = await apiClient.get<NotificationsListResponse>("/api/notifications", { params: { limit, filter, offset } });
  return data;
}

export async function fetchNotification(id: number): Promise<NotificationItem> {
  const { data } = await apiClient.get<NotificationItem>(`/api/notifications/${id}`);
  return data;
}

export async function fetchUnreadNotificationsCount(): Promise<number> {
  const { data } = await apiClient.get<{ unreadCount: number }>("/api/notifications/unread-count");
  return data.unreadCount;
}

export async function markNotificationSeen(id: number): Promise<NotificationItem> {
  const { data } = await apiClient.post<NotificationItem>(`/api/notifications/${id}/seen`);
  return data;
}

export async function markAllNotificationsSeen(): Promise<{ updated: number }> {
  const { data } = await apiClient.post<{ updated: number }>("/api/notifications/mark-all-seen");
  return data;
}

export type NotificationPreferenceItem = {
  type: string;
  inAppEnabled: boolean;
  emailEnabled: boolean;
  pushEnabled: boolean;
};

export async function fetchNotificationPreferences(): Promise<NotificationPreferenceItem[]> {
  const { data } = await apiClient.get<{ items: NotificationPreferenceItem[] }>("/api/me/notification-preferences");
  return data.items;
}

export type NotificationPreferencePatch = Partial<Pick<NotificationPreferenceItem, "inAppEnabled" | "emailEnabled" | "pushEnabled">>;

export async function updateNotificationPreference(
  type: string,
  patch: NotificationPreferencePatch,
): Promise<NotificationPreferenceItem> {
  const { data } = await apiClient.patch<NotificationPreferenceItem>(`/api/me/notification-preferences/${type}`, patch);
  return data;
}
