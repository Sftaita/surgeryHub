import { apiClient } from "../../../api/apiClient";

export type NotificationItem = {
  id: number;
  eventType: string;
  missionId: number | null;
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

export type NotificationsListResponse = {
  items: NotificationItem[];
  unreadCount: number;
};

export async function fetchNotifications(limit = 50): Promise<NotificationsListResponse> {
  const { data } = await apiClient.get<NotificationsListResponse>("/api/notifications", { params: { limit } });
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
