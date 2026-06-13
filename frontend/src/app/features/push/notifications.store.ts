export type NotificationType =
  | 'MISSION_ASSIGNED'
  | 'ENCODING_REMINDER'
  | 'MISSION_VALIDATED'
  | 'NEW_OFFER';

export type AppNotification = {
  id: string;
  type: NotificationType;
  title: string;
  body: string;
  data?: Record<string, any>;
  readAt: string | null;
  createdAt: string;
};

const STORAGE_KEY = 'surgicalhub.notifications.v1';
const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;
export const NOTIFICATIONS_UPDATED_EVENT = 'surgicalhub:notifications-updated';

function load(): AppNotification[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const all: AppNotification[] = JSON.parse(raw);
    const cutoff = Date.now() - MAX_AGE_MS;
    return all.filter((n) => new Date(n.createdAt).getTime() > cutoff);
  } catch {
    return [];
  }
}

function save(notifications: AppNotification[]) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(notifications));
    window.dispatchEvent(new Event(NOTIFICATIONS_UPDATED_EVENT));
  } catch {}
}

export const notificationsStore = {
  getAll(): AppNotification[] {
    return load();
  },

  add(n: Omit<AppNotification, 'id' | 'createdAt'>): AppNotification {
    const all = load();
    const created: AppNotification = {
      ...n,
      id: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
      createdAt: new Date().toISOString(),
    };
    save([created, ...all]);
    return created;
  },

  markAllRead() {
    const readAt = new Date().toISOString();
    save(load().map((n) => ({ ...n, readAt: n.readAt ?? readAt })));
  },

  getUnreadCount(): number {
    return load().filter((n) => !n.readAt).length;
  },
};
