const ACCESS_KEY = "sh_access_token";
const REFRESH_KEY = "sh_refresh_token";

export const authStore = {
  getAccessToken(): string | null {
    return localStorage.getItem(ACCESS_KEY);
  },
  setAccessToken(token: string) {
    localStorage.setItem(ACCESS_KEY, token);
  },
  getRefreshToken(): string | null {
    return localStorage.getItem(REFRESH_KEY);
  },
  setRefreshToken(token: string) {
    localStorage.setItem(REFRESH_KEY, token);
  },
  clear() {
    localStorage.removeItem(ACCESS_KEY);
    localStorage.removeItem(REFRESH_KEY);
  },
};
