import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import Checkbox from "@mui/material/Checkbox";
import FormControlLabel from "@mui/material/FormControlLabel";
import { useAuth } from "../auth/AuthContext";
import { consumeSessionExpired } from "../auth/authStorage";

type LocationState = { from?: string } | null;

function getNiceErrorMessage(err: any): string {
  const status = err?.response?.status;
  if (status === 401) return "Email ou mot de passe incorrect.";
  if (status === 429) return "Trop de tentatives. Réessaie dans quelques instants.";
  if (status >= 500) return "Erreur serveur. Réessaie plus tard.";
  if (typeof err?.message === "string") return err.message;
  return "Erreur réseau. Vérifie ta connexion.";
}

// ── Brand ──────────────────────────────────────────────────────────────────────
const GREEN       = "#42A882";
const GREEN_DARK  = "#2E7A5E";

function LogoSVG({ white = false, size = 32 }: { white?: boolean; size?: number }) {
  const c = white ? "#fff" : GREEN;
  return (
    <svg width={size} height={size} viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="40" cy="40" r="37" stroke={c} strokeWidth="4" />
      <line x1="27" y1="57" x2="50" y2="25" stroke={c} strokeWidth="3.5" strokeLinecap="round" />
      <path d={`M46 21 L54 30 Q58 25 53 21 Z`} fill={c} />
      <line x1="30" y1="55" x2="35" y2="59" stroke={c} strokeWidth="2" strokeLinecap="round" />
      <line x1="55" y1="57" x2="32" y2="25" stroke={c} strokeWidth="3.5" strokeLinecap="round" />
      <path d="M30 23 Q27 29 31 31 L35 23 Z" fill={c} />
      <rect x="50" y="50" width="8" height="3.5" rx="1.75" transform="rotate(-55 54 52)" fill={c} />
    </svg>
  );
}

function EyeIcon({ open }: { open: boolean }) {
  return open ? (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
      <circle cx="12" cy="12" r="3"/>
    </svg>
  ) : (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
      <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
      <line x1="1" y1="1" x2="23" y2="23"/>
    </svg>
  );
}

export default function LoginPage() {
  const { state, login } = useAuth();
  const navigate          = useNavigate();
  const location          = useLocation();
  const from              = (location.state as LocationState)?.from ?? "/";

  const [email,      setEmail]      = useState("");
  const [password,   setPassword]   = useState("");
  const [rememberMe, setRememberMe] = useState(false);
  const [showPwd,    setShowPwd]    = useState(false);
  const [error,      setError]      = useState<string | null>(null);
  const [sessionExpired, setSessionExpired] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const canSubmit = useMemo(
    () => email.trim().length > 0 && password.length > 0 && !submitting,
    [email, password, submitting],
  );

  useEffect(() => {
    if (state.status === "authenticated") navigate(from, { replace: true });
  }, [state.status, navigate, from]);

  useEffect(() => {
    if (consumeSessionExpired()) setSessionExpired(true);
  }, []);

  function clearError() { if (error) setError(null); }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!canSubmit) return;
    setError(null);
    setSubmitting(true);
    try {
      await login(email.trim(), password, rememberMe);
    } catch (err: any) {
      setError(getNiceErrorMessage(err));
      setSubmitting(false);
    }
  }

  // ── Input focus helpers ────────────────────────────────────────────────────
  const inputBase: React.CSSProperties = {
    width: "100%",
    padding: "12px 14px",
    borderRadius: 10,
    border: "1.5px solid #E2E8F0",
    outline: "none",
    fontSize: ".9rem",
    background: "#fff",
    color: "#1E293B",
    fontFamily: "inherit",
    boxSizing: "border-box",
    transition: "border-color .15s, box-shadow .15s",
  };
  const focusStyle  = { borderColor: GREEN,     boxShadow: `0 0 0 3px rgba(66,168,130,.15)` };
  const blurStyle   = { borderColor: "#E2E8F0", boxShadow: "none" };
  const errorStyle  = { borderColor: "#EF4444", boxShadow: `0 0 0 3px rgba(239,68,68,.1)` };

  return (
    <div style={{
      minHeight: "100vh",
      display: "grid",
      gridTemplateColumns: "1fr 1fr",
      fontFamily: "'Inter', system-ui, sans-serif",
      WebkitFontSmoothing: "antialiased",
    }}>

      {/* ── Left — branding ─────────────────────────────── */}
      <div style={{
        background: `linear-gradient(150deg, ${GREEN_DARK} 0%, ${GREEN} 65%, #7DD9BB 100%)`,
        display: "flex",
        flexDirection: "column",
        justifyContent: "space-between",
        padding: "48px 56px",
        position: "relative",
        overflow: "hidden",
      }}>
        {/* Cercles décoratifs */}
        <div style={{ position: "absolute", top: -100, right: -100, width: 380, height: 380, borderRadius: "50%", background: "rgba(255,255,255,.06)", pointerEvents: "none" }} />
        <div style={{ position: "absolute", bottom: -80,  left:  -80, width: 300, height: 300, borderRadius: "50%", background: "rgba(255,255,255,.04)", pointerEvents: "none" }} />
        <div style={{ position: "absolute", top: "40%", left: "55%", width: 180, height: 180, borderRadius: "50%", background: "rgba(255,255,255,.04)", pointerEvents: "none" }} />

        {/* Logo haut */}
        <div style={{ display: "flex", alignItems: "center", gap: 12, position: "relative", zIndex: 1 }}>
          <div style={{ background: "rgba(255,255,255,.18)", borderRadius: 12, padding: 10, display: "flex" }}>
            <LogoSVG white size={28} />
          </div>
          <div>
            <div style={{ fontSize: ".65rem", fontWeight: 700, letterSpacing: 2, color: "rgba(255,255,255,.65)", textTransform: "uppercase" as const }}>Surgery</div>
            <div style={{ fontSize: "1rem", fontWeight: 800, color: "#fff", letterSpacing: -0.5 }}>Hub</div>
          </div>
        </div>

        {/* Texte central */}
        <div style={{ position: "relative", zIndex: 1 }}>
          <div style={{
            display: "inline-block",
            background: "rgba(255,255,255,.18)",
            color: "#fff",
            fontSize: ".68rem", fontWeight: 700, letterSpacing: 1.2,
            textTransform: "uppercase" as const,
            padding: "5px 14px", borderRadius: 999, marginBottom: 24,
          }}>
            Plateforme interne
          </div>
          <h1 style={{
            fontSize: "clamp(1.9rem, 2.8vw, 2.8rem)",
            fontWeight: 800, color: "#fff",
            lineHeight: 1.15, letterSpacing: -1,
            margin: "0 0 18px",
          }}>
            Gérez vos missions<br />
            instrumentistes<br />
            en toute simplicité.
          </h1>
          <p style={{ fontSize: ".92rem", color: "rgba(255,255,255,.72)", lineHeight: 1.7, margin: 0, maxWidth: 370 }}>
            Planification, encodage et facturation — tout ce dont Surgery Hub a besoin pour coordonner ses équipes et ses établissements partenaires.
          </p>
        </div>

        {/* Stats bas */}
        <div style={{ display: "flex", gap: 32, position: "relative", zIndex: 1 }}>
          {[
            { n: "180+",   l: "Instrumentistes" },
            { n: "45+",    l: "Établissements" },
            { n: "2 400+", l: "Missions / an" },
          ].map((s) => (
            <div key={s.l}>
              <div style={{ fontSize: "1.4rem", fontWeight: 800, color: "#fff", lineHeight: 1, letterSpacing: -0.5 }}>{s.n}</div>
              <div style={{ fontSize: ".72rem", color: "rgba(255,255,255,.58)", marginTop: 4, fontWeight: 500 }}>{s.l}</div>
            </div>
          ))}
        </div>
      </div>

      {/* ── Right — formulaire ──────────────────────────── */}
      <div style={{
        display: "flex",
        flexDirection: "column",
        justifyContent: "center",
        alignItems: "center",
        padding: "48px 40px",
        background: "#F8FAFC",
      }}>
        <div style={{ width: "100%", maxWidth: 400 }}>

          {/* Logo + titre */}
          <div style={{ display: "flex", alignItems: "center", gap: 14, marginBottom: 36 }}>
            <LogoSVG size={42} />
            <div>
              <div style={{ fontSize: "1.1rem", fontWeight: 800, color: GREEN_DARK, letterSpacing: -0.5, lineHeight: 1.1 }}>Surgery Hub</div>
              <div style={{ fontSize: ".73rem", color: "#94A3B8", fontWeight: 500, marginTop: 2 }}>Espace de gestion</div>
            </div>
          </div>

          <h2 style={{ fontSize: "1.55rem", fontWeight: 800, color: "#0F172A", letterSpacing: -0.5, margin: "0 0 6px" }}>
            Connexion
          </h2>
          <p style={{ fontSize: ".86rem", color: "#64748B", margin: "0 0 28px", lineHeight: 1.5 }}>
            Accédez à votre espace Surgery Hub.
          </p>

          {sessionExpired && (
            <div style={{
              padding: "10px 14px",
              borderRadius: 10,
              background: "#F0F9FF",
              border: "1px solid #BAE6FD",
              fontSize: ".82rem",
              color: "#0369A1",
              marginBottom: 16,
            }}>
              Votre session a expiré. Merci de vous reconnecter.
            </div>
          )}

          <form onSubmit={onSubmit} noValidate style={{ display: "flex", flexDirection: "column", gap: 16 }}>

            {/* Email */}
            <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
              <label style={{ fontSize: ".78rem", fontWeight: 600, color: "#334155" }}>Adresse email</label>
              <input
                type="email"
                autoComplete="email"
                value={email}
                onChange={(e) => { clearError(); setEmail(e.target.value); }}
                disabled={submitting}
                placeholder="vous@surgeryhub.be"
                style={{ ...inputBase, ...(error ? errorStyle : {}) }}
                onFocus={(e) => Object.assign(e.target.style, focusStyle)}
                onBlur={(e)  => Object.assign(e.target.style, error ? errorStyle : blurStyle)}
              />
            </div>

            {/* Mot de passe */}
            <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
              <label style={{ fontSize: ".78rem", fontWeight: 600, color: "#334155" }}>Mot de passe</label>
              <div style={{ position: "relative" }}>
                <input
                  type={showPwd ? "text" : "password"}
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => { clearError(); setPassword(e.target.value); }}
                  disabled={submitting}
                  placeholder="••••••••"
                  style={{ ...inputBase, paddingRight: 44, ...(error ? errorStyle : {}) }}
                  onFocus={(e) => Object.assign(e.target.style, focusStyle)}
                  onBlur={(e)  => Object.assign(e.target.style, error ? errorStyle : blurStyle)}
                />
                <button
                  type="button"
                  onClick={() => setShowPwd((v) => !v)}
                  tabIndex={-1}
                  style={{
                    position: "absolute", right: 12, top: "50%", transform: "translateY(-50%)",
                    background: "none", border: "none", cursor: "pointer",
                    color: "#94A3B8", display: "flex", alignItems: "center", padding: 0,
                  }}
                >
                  <EyeIcon open={showPwd} />
                </button>
              </div>
            </div>

            {/* Se souvenir de moi */}
            <FormControlLabel
              control={
                <Checkbox
                  checked={rememberMe}
                  onChange={(e) => setRememberMe(e.target.checked)}
                  disabled={submitting}
                  size="small"
                  sx={{
                    color: "#94A3B8",
                    "&.Mui-checked": { color: GREEN },
                    padding: "4px 8px",
                  }}
                />
              }
              label="Se souvenir de moi"
              sx={{
                marginLeft: 0,
                "& .MuiFormControlLabel-label": {
                  fontSize: ".82rem",
                  color: "#334155",
                  fontWeight: 500,
                },
              }}
            />

            {/* Erreur */}
            {error && (
              <div style={{
                padding: "10px 14px",
                borderRadius: 10,
                background: "#FEF2F2",
                border: "1px solid #FECACA",
                fontSize: ".82rem",
                color: "#DC2626",
                display: "flex",
                alignItems: "center",
                gap: 8,
              }}>
                <span>⚠️</span> {error}
              </div>
            )}

            {/* Bouton */}
            <button
              type="submit"
              disabled={!canSubmit}
              style={{
                marginTop: 6,
                padding: "13px 24px",
                borderRadius: 999,
                border: "none",
                fontSize: ".9rem",
                fontWeight: 700,
                cursor: canSubmit ? "pointer" : "not-allowed",
                background: canSubmit
                  ? `linear-gradient(135deg, ${GREEN}, ${GREEN_DARK})`
                  : "#E2E8F0",
                color: canSubmit ? "#fff" : "#94A3B8",
                boxShadow: canSubmit ? "0 4px 14px rgba(66,168,130,.4)" : "none",
                transition: "all .2s",
                fontFamily: "inherit",
              }}
            >
              {submitting ? "Connexion en cours…" : "Se connecter →"}
            </button>

          </form>

          {/* Footer */}
          <div style={{ marginTop: 40, paddingTop: 24, borderTop: "1px solid #F1F5F9" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 10 }}>
              <div style={{ width: 7, height: 7, borderRadius: "50%", background: GREEN, flexShrink: 0 }} />
              <span style={{ fontSize: ".73rem", color: "#94A3B8", fontWeight: 500 }}>
                Accès réservé aux collaborateurs Surgery Hub
              </span>
            </div>
            <a href="/" style={{ fontSize: ".73rem", color: GREEN_DARK, textDecoration: "none", fontWeight: 600 }}>
              ← Retour au site public
            </a>
          </div>

        </div>
      </div>

    </div>
  );
}
