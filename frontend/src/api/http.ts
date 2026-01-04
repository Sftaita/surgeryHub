import axios, { AxiosError, type InternalAxiosRequestConfig } from "axios";
import { authStore } from "../auth/authStore";
import { refresh as refreshCall } from "../auth/authApi";
import type { RefreshResponseDTO } from "./types";

const baseURL = import.meta.env.VITE_API_BASE_URL as string;

export const http = axios.create({
  baseURL,
  headers: { "Content-Type": "application/json" },
});

http.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = authStore.getAccessToken();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

let isRefreshing = false;
let refreshQueue: Array<(token: string | null) => void> = [];

function resolveQueue(token: string | null) {
  refreshQueue.forEach((cb) => cb(token));
  refreshQueue = [];
}

http.interceptors.response.use(
  (res) => res,
  async (error: AxiosError) => {
    const status = error.response?.status;
    const originalRequest = error.config as
      | (InternalAxiosRequestConfig & { _retry?: boolean })
      | undefined;

    // Only attempt refresh on 401, once
    if (status === 401 && originalRequest && !originalRequest._retry) {
      originalRequest._retry = true;

      const currentRefreshToken = authStore.getRefreshToken();
      if (!currentRefreshToken) {
        authStore.clear();
        return Promise.reject(error);
      }

      if (isRefreshing) {
        // Wait for refresh result then retry
        return new Promise((resolve, reject) => {
          refreshQueue.push((token) => {
            if (!token) return reject(error);
            originalRequest.headers.Authorization = `Bearer ${token}`;
            resolve(http(originalRequest));
          });
        });
      }

      isRefreshing = true;
      try {
        const data: RefreshResponseDTO = await refreshCall(currentRefreshToken);
        authStore.setAccessToken(data.accessToken);
        if (data.refreshToken) authStore.setRefreshToken(data.refreshToken);

        resolveQueue(data.accessToken);

        originalRequest.headers.Authorization = `Bearer ${data.accessToken}`;
        return http(originalRequest);
      } catch (e) {
        resolveQueue(null);
        authStore.clear();
        return Promise.reject(e);
      } finally {
        isRefreshing = false;
      }
    }

    return Promise.reject(error);
  }
);
