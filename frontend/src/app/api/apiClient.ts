import axios, { AxiosError, InternalAxiosRequestConfig } from "axios";
import {
  getAccessToken,
  getRefreshToken,
  setTokens,
  clearTokens,
} from "../auth/authTokens";
import { refreshTokens } from "../auth/authApi";
import { getRefreshPromise, setRefreshPromise } from "../auth/refreshMutex";
import { markSessionExpired } from "../auth/authStorage";
import { dispatchSessionExpired } from "../auth/sessionExpiredEvent";

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  timeout: 10_000,
});

/**
 * D-157 — login, Google login, refresh and logout never carry the access token. Sending the
 * (usually expired) access token along with /api/auth/refresh made the server answer
 * "Expired JWT Token" before it even read the refresh token: every access-token expiry ended in
 * a logout (docs/decisions.md D-157).
 */
function isAuthEndpoint(url: string | undefined): boolean {
  return !!url && /\/api\/auth\//.test(url);
}

type RetryableConfig = InternalAxiosRequestConfig & { _retry?: boolean; _sentAccessToken?: string | null };

// 1) Request interceptor : injecte Bearer automatiquement (hors endpoints d'authentification)
apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  if (isAuthEndpoint(config.url)) {
    if (config.headers) delete config.headers.Authorization;
    return config;
  }
  const token = getAccessToken();
  (config as RetryableConfig)._sentAccessToken = token;
  if (token) {
    config.headers = config.headers ?? {};
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

function sentRefreshToken(data: unknown): string | null {
  try {
    const body = typeof data === "string" ? JSON.parse(data) : data;
    return (body as { refresh_token?: string } | null)?.refresh_token ?? null;
  } catch {
    return null;
  }
}

function expireSession() {
  markSessionExpired();
  clearTokens();
  dispatchSessionExpired();
}

// 2) Response interceptor : gère 401 avec refresh + retry
apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const status = error.response?.status;
    const originalRequest = error.config as RetryableConfig | undefined;

    // Seul un 401 concerne la session : 403 (refus métier), 5xx, timeout, réseau coupé
    // remontent à l'appelant sans jamais toucher à la session.
    if (status !== 401 || !originalRequest) throw error;

    // Le refresh lui-même a été refusé : la session ne peut plus être restaurée
    // (expirée, révoquée, compte suspendu). Seul cas de déconnexion automatique.
    if (originalRequest.url?.includes("/api/auth/refresh")) {
      setRefreshPromise(null);
      // …sauf si un autre onglet a entre-temps remplacé la session stockée : celle-ci reste valable.
      const stored = getRefreshToken();
      const sent = sentRefreshToken(originalRequest.data);
      if (stored === null || sent === null || stored === sent) expireSession();
      throw error;
    }

    // Login / logout / Google : un 401 y est une réponse métier (identifiants invalides…),
    // jamais un signal de session à rafraîchir.
    if (isAuthEndpoint(originalRequest.url)) throw error;

    // Évite boucle infinie
    if (originalRequest._retry) throw error;
    originalRequest._retry = true;

    // Un autre onglet ou une autre requête a déjà renouvelé le jeton pendant que celle-ci
    // était en vol : on rejoue simplement avec le jeton courant, sans nouveau refresh.
    const current = getAccessToken();
    if (current && current !== originalRequest._sentAccessToken) {
      originalRequest.headers = originalRequest.headers ?? {};
      originalRequest.headers.Authorization = `Bearer ${current}`;
      return apiClient(originalRequest);
    }

    const rt = getRefreshToken();
    if (!rt) {
      expireSession();
      throw error;
    }

    // Mutex : si un refresh est déjà en cours, on attend
    let p = getRefreshPromise();
    if (!p) {
      p = refreshTokens(rt);
      setRefreshPromise(p);
    }

    try {
      const newTokens = await p;
      // Ne réécrit que si la session n'a pas été fermée entre-temps (logout dans un autre onglet).
      if (getRefreshToken() !== null) setTokens(newTokens);
      setRefreshPromise(null);

      // Rejoue la requête initiale avec le nouveau token
      originalRequest.headers = originalRequest.headers ?? {};
      originalRequest.headers.Authorization = `Bearer ${newTokens.accessToken}`;
      return apiClient(originalRequest);
    } catch (e) {
      setRefreshPromise(null);

      // Re-audit Remember Me (Task 11) — ne déconnecter que si le refresh token est
      // RÉELLEMENT invalide (401 sur /api/auth/refresh, déjà géré ci-dessus). Une erreur
      // réseau/timeout transitoire pendant l'appel de refresh (Wi-Fi instable, latence,
      // le `timeout: 10_000` ci-dessus) ne prouve rien sur la validité du refresh token :
      // la session est conservée, une prochaine requête retentera le refresh
      // (docs/decisions.md D-047, réinvestigation ; D-157).
      const refreshError = e as AxiosError;
      if (refreshError?.response?.status === 401 && getRefreshToken() === rt) {
        expireSession();
      }
      throw error;
    }
  }
);
