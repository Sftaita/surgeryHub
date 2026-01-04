import { clearAuth, readAuth, writeAuth } from "./authStorage";

export function getAccessToken(): string | null {
  return readAuth()?.accessToken ?? null;
}

export function getRefreshToken(): string | null {
  return readAuth()?.refreshToken ?? null;
}

export function setTokens(tokens: {
  accessToken: string;
  refreshToken: string;
}) {
  writeAuth(tokens);
}

export function clearTokens() {
  clearAuth();
}
