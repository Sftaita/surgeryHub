import { useCallback, useEffect, useState } from 'react';
import {
  notificationsStore,
  AppNotification,
  NOTIFICATIONS_UPDATED_EVENT,
} from './notifications.store';

export function useNotifications() {
  const [notifications, setNotifications] = useState<AppNotification[]>(() =>
    notificationsStore.getAll(),
  );

  const refresh = useCallback(() => {
    setNotifications(notificationsStore.getAll());
  }, []);

  // Sync when other tabs/windows write
  useEffect(() => {
    window.addEventListener(NOTIFICATIONS_UPDATED_EVENT, refresh);
    return () => window.removeEventListener(NOTIFICATIONS_UPDATED_EVENT, refresh);
  }, [refresh]);

  // Listen for SW push messages
  useEffect(() => {
    if (!('serviceWorker' in navigator)) return;

    const handler = (event: MessageEvent) => {
      if (event.data?.type !== 'PUSH_NOTIFICATION') return;
      const { title, body, data } = event.data.payload ?? {};
      notificationsStore.add({
        type: 'NEW_OFFER',
        title: title ?? 'SurgicalHub',
        body: body ?? '',
        data,
        readAt: null,
      });
      refresh();
    };

    navigator.serviceWorker.addEventListener('message', handler);
    return () => navigator.serviceWorker.removeEventListener('message', handler);
  }, [refresh]);

  const markAllRead = useCallback(() => {
    notificationsStore.markAllRead();
    refresh();
  }, [refresh]);

  const addNotification = useCallback(
    (n: Omit<AppNotification, 'id' | 'createdAt'>) => {
      notificationsStore.add(n);
      refresh();
    },
    [refresh],
  );

  const unreadCount = notifications.filter((n) => !n.readAt).length;
  const badgeLabel = unreadCount === 0 ? undefined : unreadCount > 9 ? '9+' : String(unreadCount);

  return { notifications, unreadCount, badgeLabel, markAllRead, addNotification };
}
