import { createContext, useContext, useEffect, useState } from "react";
import { apiClient } from "../api/apiClient";
import { clearAuth, readAuth, writeAuth } from "./authStorage";

/* ======================
   Types
====================== */

type User = {
  id: number;
  role: string; // ex: "ROLE_ADMIN", "ROLE_SURGEON"
  sites: unknown[]; // typage fin plus tard (LOT métier)
};

type AuthState =
  | { status: "anonymous" }
  | { status: "loading" }
  | { status: "authenticated"; user: User };

type AuthContextType = {
  state: AuthState;
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
};

/* ======================
   Context
====================== */

const AuthContext = createContext<AuthContextType | null>(null);

/* ======================
   Helpers
====================== */

function normalizeLoginPayload(data: any) {
  const accessToken =
    data.accessToken ?? data.access_token ?? data.token ?? null;

  const refreshToken = data.refreshToken ?? data.refresh_token ?? null;

  if (!accessToken) {
    throw new Error("Login payload invalide : token manquant");
  }
  if (!refreshToken) {
    throw new Error("Login payload invalide : refresh_token manquant");
  }

  return { accessToken, refreshToken };
}

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
  async function login(email: string, password: string) {
    setState({ status: "loading" });

    const res = await apiClient.post("/api/auth/login", {
      email,
      password,
    });

    const tokens = normalizeLoginPayload(res.data);
    writeAuth(tokens);

    const me = await apiClient.get("/api/me");

    setState({ status: "authenticated", user: me.data });
  }

  /**
   * LOGOUT
   */
  function logout() {
    clearAuth();
    setState({ status: "anonymous" });
  }

  return (
    <AuthContext.Provider value={{ state, login, logout }}>
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
