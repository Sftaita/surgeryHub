import { createContext, useContext, useEffect, useState } from "react";
import { apiClient } from "../api/apiClient";
import { clearAuth, readAuth, writeAuth } from "./authStorage";
import { loginRequest, logoutRequest } from "./authApi";

/* ======================
   Types
====================== */

type User = {
  id: number;
  role: string;
  sites: unknown[];
  firstname?: string | null;
  lastname?: string | null;
  profilePictureUrl?: string | null;
};

type AuthState =
  | { status: "anonymous" }
  | { status: "loading" }
  | { status: "authenticated"; user: User };

type AuthContextType = {
  state: AuthState;
  login: (email: string, password: string, rememberMe?: boolean) => Promise<void>;
  logout: () => void;
  refreshUser: () => Promise<void>;
};

/* ======================
   Context
====================== */

const AuthContext = createContext<AuthContextType | null>(null);

/* ======================
   Provider
====================== */

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: "anonymous" });

  /**
   * BOOTSTRAP
   * Au chargement de l’app :
   * - si tokens présents → /api/me
   * - sinon → anonymous
   */
  useEffect(() => {
    const stored = readAuth();
    if (!stored) return;

    setState({ status: "loading" });

    apiClient
      .get("/api/me") // ⚠️ plus jamais de header ici
      .then((res) => {
        setState({ status: "authenticated", user: res.data });
      })
      .catch(() => {
        clearAuth();
        setState({ status: "anonymous" });
      });
  }, []);

  /**
   * LOGIN
   */
  async function login(email: string, password: string, rememberMe = false) {
    setState({ status: "loading" });

    const tokens = await loginRequest(email, password, rememberMe);
    writeAuth(tokens);

    const me = await apiClient.get("/api/me");

    setState({ status: "authenticated", user: me.data });
  }

  /**
   * LOGOUT
   */
  function logout() {
    const stored = readAuth();
    if (stored?.refreshToken) {
      void logoutRequest(stored.refreshToken);
    }
    clearAuth();
    setState({ status: "anonymous" });
  }

  /**
   * REFRESH USER
   * Re-fetches /api/me and updates the authenticated state in place — used after
   * self-service changes (e.g. profile picture upload) so the rest of the app
   * (avatar, prompt modal) reflects the new data without a full reload.
   */
  async function refreshUser() {
    const me = await apiClient.get("/api/me");
    setState({ status: "authenticated", user: me.data });
  }

  return (
    <AuthContext.Provider value={{ state, login, logout, refreshUser }}>
      {children}
    </AuthContext.Provider>
  );
}

/* ======================
   Hook
====================== */

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth must be used inside AuthProvider");
  }
  return ctx;
}
