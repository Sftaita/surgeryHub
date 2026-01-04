import axios, { AxiosError, InternalAxiosRequestConfig } from "axios";
import {
  getAccessToken,
  getRefreshToken,
  setTokens,
  clearTokens,
} from "../auth/authTokens";
import { refreshTokens } from "../auth/authApi";
import { getRefreshPromise, setRefreshPromise } from "../auth/refreshMutex";

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  timeout: 10_000,
});

// 1) Request interceptor : injecte Bearer automatiquement
apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = getAccessToken();
  if (token) {
    config.headers = config.headers ?? {};
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// 2) Response interceptor : gère 401 avec refresh + retry
apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const status = error.response?.status;
    const originalRequest = error.config as any;

    // Si pas 401, on laisse remonter
    if (status !== 401) throw error;

    // Évite boucle infinie
    if (originalRequest?._retry) {
      throw error;
    }
    originalRequest._retry = true;

    const rt = getRefreshToken();
    if (!rt) {
      clearTokens();
      // La redirection sera gérée par AuthProvider/Guard ensuite
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
      setTokens(newTokens);
      setRefreshPromise(null);

      // Rejoue la requête initiale avec le nouveau token
      originalRequest.headers = originalRequest.headers ?? {};
      originalRequest.headers.Authorization = `Bearer ${newTokens.accessToken}`;
      return apiClient(originalRequest);
    } catch (e) {
      setRefreshPromise(null);
      clearTokens();
      throw error;
    }
  }
);
