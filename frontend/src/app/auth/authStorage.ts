const STORAGE_KEY = "surgicalhub.auth.v2";

export type StoredAuth = {
  accessToken: string;
  refreshToken: string;
};

export function readAuth(): StoredAuth | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;

    const parsed = JSON.parse(raw) as Partial<StoredAuth>;
    if (!parsed.accessToken || !parsed.refreshToken) {
      clearAuth();
      return null;
    }
    return parsed as StoredAuth;
  } catch {
    clearAuth();
    return null;
  }
}

export function writeAuth(auth: StoredAuth) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(auth));
}

export function clearAuth() {
  localStorage.removeItem(STORAGE_KEY);
}
