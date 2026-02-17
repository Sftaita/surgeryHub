import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";

type LocationState = { from?: string } | null;

function getNiceErrorMessage(err: any): string {
  // Axios: err.response?.status
  const status = err?.response?.status;
  if (status === 401) return "Email ou mot de passe incorrect.";
  if (status === 429)
    return "Trop de tentatives. Réessaie dans quelques instants.";
  if (status >= 500) return "Erreur serveur. Réessaie plus tard.";
  if (typeof err?.message === "string") return err.message;
  return "Erreur réseau. Vérifie ta connexion.";
}

export default function LoginPage() {
  const { state, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const from = (location.state as LocationState)?.from ?? "/";

  const [email, setEmail] = useState<string>("");
  const [password, setPassword] = useState<string>("");

  const [error, setError] = useState<string | null>(null);

  // IMPORTANT: on ne dépend pas du state.status === "loading"
  // car ton AuthContext peut rester coincé en loading en cas d’erreur.
  const [submitting, setSubmitting] = useState(false);

  const canSubmit = useMemo(() => {
    return email.trim().length > 0 && password.length > 0 && !submitting;
  }, [email, password, submitting]);

  // Si déjà authentifié → redirect
  useEffect(() => {
    if (state.status === "authenticated") {
      navigate(from, { replace: true });
    }
  }, [state.status, navigate, from]);

  // Clear erreur dès qu’on retape (évite “vieux message”)
  useEffect(() => {
    if (!error) return;
    // Dès que l’utilisateur modifie un champ, on clear
    // (on ne met pas email/password en deps direct sinon ça clear trop agressif au montage)
  }, [error]);

  function onChangeEmail(v: string) {
    if (error) setError(null);
    setEmail(v);
  }
  function onChangePassword(v: string) {
    if (error) setError(null);
    setPassword(v);
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!canSubmit) return;

    setError(null);
    setSubmitting(true);

    try {
      await login(email.trim(), password);
      // la redirection se fait via useEffect (state.authenticated)
    } catch (err: any) {
      setError(getNiceErrorMessage(err));
      setSubmitting(false); // permet de retenter immédiatement
    }
  }

  return (
    <div
      style={{
        minHeight: "100vh",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        padding: 16,
      }}
    >
      <form
        onSubmit={onSubmit}
        style={{
          width: "100%",
          maxWidth: 520,
          border: "1px solid rgba(0,0,0,0.12)",
          borderRadius: 16,
          padding: 18,
          background: "white",
        }}
      >
        <h2 style={{ marginTop: 0, marginBottom: 14, fontSize: 34 }}>Login</h2>

        <div style={{ display: "grid", gap: 12 }}>
          <label style={{ display: "grid", gap: 6 }}>
            <span style={{ fontSize: 16, fontWeight: 600 }}>Email</span>
            <input
              type="email"
              autoComplete="email"
              value={email}
              onChange={(e) => onChangeEmail(e.target.value)}
              disabled={submitting}
              style={{
                padding: "12px 14px",
                borderRadius: 10,
                border: "1px solid rgba(0,0,0,0.25)",
                outline: "none",
                fontSize: 16,
              }}
            />
          </label>

          <label style={{ display: "grid", gap: 6 }}>
            <span style={{ fontSize: 16, fontWeight: 600 }}>Mot de passe</span>
            <input
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => onChangePassword(e.target.value)}
              disabled={submitting}
              style={{
                padding: "12px 14px",
                borderRadius: 10,
                border: "1px solid rgba(0,0,0,0.25)",
                outline: "none",
                fontSize: 16,
              }}
            />
          </label>

          {error && (
            <div
              role="alert"
              style={{
                padding: 12,
                borderRadius: 10,
                background: "rgba(255,0,0,0.06)",
                border: "1px solid rgba(255,0,0,0.25)",
                fontSize: 15,
              }}
            >
              {error}
            </div>
          )}

          <button
            type="submit"
            disabled={!canSubmit}
            style={{
              padding: "12px 14px",
              borderRadius: 10,
              border: "1px solid rgba(0,0,0,0.15)",
              fontSize: 16,
              fontWeight: 700,
              cursor: canSubmit ? "pointer" : "not-allowed",
              background: canSubmit ? "#1976d2" : "rgba(0,0,0,0.12)",
              color: canSubmit ? "white" : "rgba(0,0,0,0.45)",
            }}
          >
            {submitting ? "Connexion…" : "Se connecter"}
          </button>

          <div style={{ fontSize: 12, opacity: 0.7 }}>
            Astuce : tu peux appuyer sur Entrée pour soumettre.
          </div>
        </div>
      </form>
    </div>
  );
}
