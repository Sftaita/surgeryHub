import * as React from "react";
import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { Box, useMediaQuery } from "@mui/material";
import { useAuth } from "../auth/AuthContext";
import { consumeSessionExpired } from "../auth/authStorage";
import { useToast } from "../ui/toast/useToast";

type LocationState = { from?: string } | null;

function getNiceErrorMessage(err: any): string {
  const status = err?.response?.status;
  if (status === 401) return "Email ou mot de passe incorrect.";
  if (status === 429) return "Trop de tentatives. Réessaie dans quelques instants.";
  if (status >= 500) return "Erreur serveur. Réessaie plus tard.";
  if (typeof err?.message === "string") return err.message;
  return "Erreur réseau. Vérifie ta connexion.";
}

// ── Design tokens (design_handoff_surgeryhub_instrumentiste/tokens/colors.css) ─
const GREEN_300 = "#8FDABF";
const GREEN_500 = "#42A882";
const GREEN_600 = "#338F6E";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_300  = "#C2C9D1";
const GRAY_400  = "#98A2AE";
const GRAY_500  = "#727E8C";
const GRAY_700  = "#3A4754";
const BORDER_DEFAULT = "#DDE2E8";
const FOCUS_RING = `0 0 0 3px rgba(66,168,130,.32)`; // --focus-ring: green-500 32%

const LOGIN_GRADIENT = "linear-gradient(155deg, #2E7D5F 0%, #1E634A 42%, #123F30 100%)";

// ── Decorative background pieces ────────────────────────────────────────────
function LoginWaves({ variant }: { variant: "mobile" | "desktop" }) {
  if (variant === "mobile") {
    return (
      <svg
        style={{ position: "absolute", inset: 0, width: "100%", height: "44%", pointerEvents: "none" }}
        viewBox="0 0 400 380"
        preserveAspectRatio="none"
        fill="none"
      >
        <path d="M0 210 C 90 150, 200 268, 400 168 L400 0 L0 0 Z" fill="rgba(255,255,255,.045)" />
        <path d="M0 300 C 130 232, 260 340, 400 250 L400 380 L0 380 Z" fill="rgba(0,0,0,.10)" />
      </svg>
    );
  }
  return (
    <svg
      style={{ position: "absolute", inset: 0, width: "100%", height: "100%", pointerEvents: "none" }}
      viewBox="0 0 400 800"
      preserveAspectRatio="none"
      fill="none"
    >
      <path d="M0 430 C 90 340, 220 520, 400 380 L400 0 L0 0 Z" fill="rgba(255,255,255,.04)" />
      <path d="M0 620 C 140 520, 280 700, 400 560 L400 800 L0 800 Z" fill="rgba(0,0,0,.10)" />
    </svg>
  );
}

function MedicalCrossWatermark({ style }: { style: React.CSSProperties }) {
  return (
    <svg style={{ position: "absolute", opacity: 0.07, pointerEvents: "none", ...style }} viewBox="0 0 100 100" fill="none">
      <circle cx="50" cy="50" r="42" stroke="#fff" strokeWidth="4" />
      <path d="M50 26v48M26 50h48" stroke="#fff" strokeWidth="12" strokeLinecap="round" />
    </svg>
  );
}

function MailIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <rect x="2" y="4" width="20" height="16" rx="2" />
      <path d="m22 7-10 6L2 7" />
    </svg>
  );
}

function LockIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <rect x="4" y="11" width="16" height="10" rx="2" />
      <path d="M8 11V7a4 4 0 0 1 8 0v4" />
    </svg>
  );
}

function EyeIcon({ open }: { open: boolean }) {
  return open ? (
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  ) : (
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
      <circle cx="12" cy="12" r="3" />
      <path d="m4 4 16 16" />
    </svg>
  );
}

function CheckIcon() {
  return (
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3.4" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}

// ── Reusable field (mirrors Field.dc.html) ──────────────────────────────────
function LoginField({
  label,
  type,
  icon,
  placeholder,
  value,
  onChange,
  disabled,
  hasError,
  autoComplete,
  onEnter,
}: {
  label: string;
  type: "email" | "password" | "text";
  icon: "mail" | "lock";
  placeholder: string;
  value: string;
  onChange: (v: string) => void;
  disabled?: boolean;
  hasError?: boolean;
  autoComplete?: string;
  onEnter?: () => void;
}) {
  const [show, setShow] = useState(false);
  const isPassword = type === "password";
  const padLeft = 46;
  const padRight = isPassword ? 52 : 16;

  return (
    <Box sx={{ display: "flex", flexDirection: "column", gap: "7px", width: "100%" }}>
      <Box component="label" sx={{ fontSize: 13, fontWeight: 700, color: GRAY_700, letterSpacing: "0.01em" }}>
        {label}
      </Box>
      <Box sx={{ position: "relative", width: "100%" }}>
        <Box
          sx={{
            position: "absolute",
            left: 16,
            top: "50%",
            transform: "translateY(-50%)",
            display: "flex",
            color: GREEN_600,
            pointerEvents: "none",
          }}
        >
          {icon === "mail" ? <MailIcon /> : <LockIcon />}
        </Box>
        <Box
          component="input"
          type={isPassword ? (show ? "text" : "password") : type}
          placeholder={placeholder}
          value={value}
          disabled={disabled}
          autoComplete={autoComplete}
          onChange={(e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value)}
          onKeyDown={(e: React.KeyboardEvent<HTMLInputElement>) => {
            if (e.key === "Enter" && onEnter) onEnter();
          }}
          sx={{
            height: 54,
            border: `1.5px solid ${hasError ? "#E5484D" : BORDER_DEFAULT}`,
            borderRadius: "14px",
            padding: `0 ${padRight}px 0 ${padLeft}px`,
            fontFamily: "inherit",
            fontSize: 16,
            color: "#16202B",
            background: "#fff",
            outline: "none",
            width: "100%",
            boxSizing: "border-box",
            transition: "border-color 150ms",
            "&:focus": {
              borderColor: GREEN_500,
              boxShadow: FOCUS_RING,
            },
          }}
        />
        {isPassword && (
          <Box
            component="button"
            type="button"
            aria-label="Afficher le mot de passe"
            onClick={() => setShow((v) => !v)}
            tabIndex={-1}
            sx={{
              position: "absolute",
              right: 6,
              top: "50%",
              transform: "translateY(-50%)",
              width: 42,
              height: 42,
              border: "none",
              background: "transparent",
              borderRadius: "11px",
              cursor: "pointer",
              color: GRAY_400,
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              transition: "all 150ms",
              "&:hover": { background: "#F1F4F7", color: "#566270" },
            }}
          >
            <EyeIcon open={!show} />
          </Box>
        )}
      </Box>
    </Box>
  );
}

// ── Remember-me toggle (mirrors cbBox) ──────────────────────────────────────
function RememberCheckbox({ checked, onToggle }: { checked: boolean; onToggle: () => void }) {
  return (
    <Box
      component="button"
      type="button"
      onClick={onToggle}
      aria-label="Se souvenir de moi"
      sx={{
        width: 22,
        height: 22,
        borderRadius: "6px",
        flexShrink: 0,
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        cursor: "pointer",
        border: "none",
        padding: 0,
        transition: "all 150ms",
        background: checked ? GREEN_600 : "#fff",
        boxShadow: checked ? "none" : `inset 0 0 0 1.5px ${GRAY_300}`,
      }}
    >
      {checked && <CheckIcon />}
    </Box>
  );
}

// ── Login button with spinner (shSpin) ──────────────────────────────────────
function LoginButton({
  loading,
  disabled,
  onClick,
  height,
}: {
  loading: boolean;
  disabled: boolean;
  onClick: () => void;
  height: number;
}) {
  return (
    <Box
      component="button"
      type="submit"
      onClick={onClick}
      disabled={disabled}
      sx={{
        height,
        width: "100%",
        border: "none",
        borderRadius: "12px",
        background: GREEN_700,
        color: "#fff",
        fontFamily: "inherit",
        fontSize: 15.5,
        fontWeight: 700,
        cursor: disabled ? "not-allowed" : "pointer",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        gap: "10px",
        boxShadow: "0 6px 18px rgba(20,77,56,.3)",
        opacity: disabled && !loading ? 0.6 : 1,
        transition: "background 150ms",
        "&:hover": !disabled ? { background: GREEN_800 } : undefined,
        "&:active": !disabled ? { transform: "translateY(0.5px)" } : undefined,
      }}
    >
      {loading && (
        <Box
          component="span"
          sx={{
            width: 17,
            height: 17,
            borderRadius: "999px",
            border: "2.5px solid rgba(255,255,255,.4)",
            borderTopColor: "#fff",
            animation: "shSpin .7s linear infinite",
            "@keyframes shSpin": { to: { transform: "rotate(360deg)" } },
          }}
        />
      )}
      <span>{loading ? "Connexion…" : "Se connecter"}</span>
    </Box>
  );
}

// ── Main page ────────────────────────────────────────────────────────────────
export default function LoginPage() {
  const { state, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as LocationState)?.from ?? "/";
  const toast = useToast();
  const isDesktop = useMediaQuery("(min-width:900px)");

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [rememberMe, setRememberMe] = useState(true);
  const [error, setError] = useState<string | null>(null);
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

  function clearError() {
    if (error) setError(null);
  }

  async function onSubmit() {
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

  function notAvailableYet() {
    toast.warning("Fonctionnalité bientôt disponible.");
  }

  const fields = (
    <Box sx={{ display: "flex", flexDirection: "column", gap: "18px" }}>
      <LoginField
        label="E-mail"
        type="email"
        icon="mail"
        placeholder="votre@email.com"
        value={email}
        onChange={(v) => { clearError(); setEmail(v); }}
        disabled={submitting}
        hasError={!!error}
        autoComplete="email"
        onEnter={onSubmit}
      />
      <LoginField
        label="Mot de passe"
        type="password"
        icon="lock"
        placeholder="••••••••"
        value={password}
        onChange={(v) => { clearError(); setPassword(v); }}
        disabled={submitting}
        hasError={!!error}
        autoComplete="current-password"
        onEnter={onSubmit}
      />
      <Box sx={{ display: "flex", alignItems: "center", gap: "10px" }}>
        <RememberCheckbox checked={rememberMe} onToggle={() => setRememberMe((v) => !v)} />
        <Box
          component="span"
          onClick={() => setRememberMe((v) => !v)}
          sx={{ fontSize: 14, fontWeight: 600, color: GRAY_700, cursor: "pointer" }}
        >
          Se souvenir de moi
        </Box>
        <Box sx={{ flex: 1 }} />
        <Box
          component="a"
          onClick={notAvailableYet}
          sx={{ fontSize: 14, fontWeight: 600, color: GREEN_700, cursor: "pointer", textDecoration: "none" }}
        >
          Mot de passe oublié&nbsp;?
        </Box>
      </Box>

      {sessionExpired && (
        <Box sx={{ px: "14px", py: "10px", borderRadius: "10px", background: "#EDF4FF", border: "1px solid #D6E6FE", fontSize: 13, color: "#1B5FD0" }}>
          Votre session a expiré. Merci de vous reconnecter.
        </Box>
      )}
      {error && (
        <Box sx={{ px: "14px", py: "10px", borderRadius: "10px", background: "#FDEEEE", border: "1px solid #FAD7D8", fontSize: 13, color: "#C62F36" }}>
          {error}
        </Box>
      )}
    </Box>
  );

  if (!isDesktop) {
    return (
      <Box
        sx={{
          minHeight: "100vh",
          display: "flex",
          flexDirection: "column",
          background: LOGIN_GRADIENT,
          position: "relative",
          overflow: "hidden",
          fontFamily: "'Inter', system-ui, sans-serif",
          WebkitFontSmoothing: "antialiased",
        }}
      >
        <LoginWaves variant="mobile" />
        <MedicalCrossWatermark style={{ left: -40, top: 96, width: 300, height: 300 }} />

        <Box sx={{ flex: "none", display: "flex", flexDirection: "column", gap: "22px", px: "26px", pt: "54px", pb: "34px", position: "relative" }}>
          <Box sx={{ display: "flex", alignItems: "center", gap: "12px" }}>
            <Box sx={{ width: 46, height: 46, borderRadius: "13px", background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", boxShadow: "0 4px 14px rgba(0,0,0,.25)", flexShrink: 0 }}>
              <Box component="img" src="/logo-mark-transparent.png" alt="SurgeryHub" sx={{ width: 34, height: 34, objectFit: "contain" }} />
            </Box>
            <Box sx={{ fontSize: 20, fontWeight: 800, letterSpacing: "-0.02em", color: "#fff" }}>
              Surgery<Box component="span" sx={{ color: GREEN_300 }}>Hub</Box>
            </Box>
          </Box>
          <Box>
            <Box component="h1" sx={{ m: 0, fontSize: 30, fontWeight: 800, letterSpacing: "-0.02em", lineHeight: 1.18, color: "#fff" }}>
              Bienvenue sur<br />Surgery<Box component="span" sx={{ color: GREEN_300 }}>Hub</Box>
            </Box>
            <Box component="p" sx={{ m: "12px 0 0", fontSize: 14.5, color: "rgba(255,255,255,.8)", lineHeight: 1.5, maxWidth: 300 }}>
              Connectez-vous à votre espace personnel
            </Box>
          </Box>
        </Box>

        <Box sx={{ flex: 1, background: "#fff", borderRadius: "26px 26px 0 0", px: "24px", pt: "28px", pb: "40px", position: "relative", boxShadow: "0 -14px 40px rgba(0,0,0,.28)" }}>
          <Box sx={{ maxWidth: 420, mx: "auto" }}>
            {fields}
            <Box sx={{ mt: "18px" }}>
              <LoginButton loading={submitting} disabled={!canSubmit} onClick={onSubmit} height={54} />
            </Box>
            <Box component="p" sx={{ mt: "24px", textAlign: "center", fontSize: 13.5, color: GRAY_500 }}>
              Vous n'avez pas de compte&nbsp;?<br />
              <Box component="a" onClick={notAvailableYet} sx={{ color: GREEN_700, fontWeight: 700, textDecoration: "none", cursor: "pointer" }}>
                Demander une invitation
              </Box>
            </Box>
          </Box>
        </Box>
      </Box>
    );
  }

  // ── Desktop / tablet ───────────────────────────────────────────────────────
  const features = [
    {
      icon: (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <rect x="3" y="4" width="18" height="17" rx="2" />
          <path d="M16 2v4M8 2v4M3 10h18" />
        </svg>
      ),
      title: "Planning intelligent",
      desc: "Consultez vos missions et disponibilités en un coup d'œil.",
    },
    {
      icon: (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
          <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
        </svg>
      ),
      title: "Offres en temps réel",
      desc: "Recevez des offres adaptées à vos compétences.",
    },
    {
      icon: (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M20 13c0 5-3.5 7.5-7.7 8.9a2 2 0 0 1-.6 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1Z" />
        </svg>
      ),
      title: "Sécurisé & fiable",
      desc: "Vos données sont protégées et confidentielles.",
    },
  ];

  return (
    <Box
      sx={{
        minHeight: "100vh",
        display: "grid",
        gridTemplateColumns: "minmax(420px,1fr) minmax(460px,1.1fr)",
        background: "#fff",
        fontFamily: "'Inter', system-ui, sans-serif",
        WebkitFontSmoothing: "antialiased",
      }}
    >
      {/* Left — branding */}
      <Box sx={{ position: "relative", overflow: "hidden", display: "flex", flexDirection: "column", px: "52px", py: "44px", background: LOGIN_GRADIENT }}>
        <LoginWaves variant="desktop" />
        <MedicalCrossWatermark style={{ right: -70, bottom: -60, width: 380, height: 380 }} />

        <Box sx={{ display: "flex", alignItems: "center", gap: "12px", position: "relative" }}>
          <Box sx={{ width: 48, height: 48, borderRadius: "14px", background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", boxShadow: "0 4px 14px rgba(0,0,0,.25)", flexShrink: 0 }}>
            <Box component="img" src="/logo-mark-transparent.png" alt="SurgeryHub" sx={{ width: 36, height: 36, objectFit: "contain" }} />
          </Box>
          <Box sx={{ fontSize: 21, fontWeight: 800, letterSpacing: "-0.02em", color: "#fff" }}>
            Surgery<Box component="span" sx={{ color: GREEN_300 }}>Hub</Box>
          </Box>
        </Box>

        <Box sx={{ flex: 1, minHeight: 36 }} />

        <Box sx={{ maxWidth: 430, position: "relative" }}>
          <Box component="h2" sx={{ m: 0, fontSize: 40, fontWeight: 800, letterSpacing: "-0.025em", lineHeight: 1.16, color: "#fff" }}>
            Bienvenue sur<br />Surgery<Box component="span" sx={{ color: GREEN_300 }}>Hub</Box>
          </Box>
          <Box component="p" sx={{ m: "16px 0 0", fontSize: 16, lineHeight: 1.55, color: "rgba(255,255,255,.82)" }}>
            La plateforme dédiée aux instrumentistes pour gérer vos missions et disponibilités.
          </Box>

          <Box sx={{ display: "flex", flexDirection: "column", gap: "22px", mt: "38px" }}>
            {features.map((f) => (
              <Box key={f.title} sx={{ display: "flex", alignItems: "flex-start", gap: "16px" }}>
                <Box sx={{ width: 48, height: 48, borderRadius: "999px", background: "rgba(255,255,255,.12)", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
                  {f.icon}
                </Box>
                <Box>
                  <Box sx={{ fontSize: 15.5, fontWeight: 700, color: "#fff" }}>{f.title}</Box>
                  <Box sx={{ mt: "3px", fontSize: 13.5, lineHeight: 1.5, color: "rgba(255,255,255,.72)" }}>{f.desc}</Box>
                </Box>
              </Box>
            ))}
          </Box>
        </Box>

        <Box sx={{ flex: 1.4, minHeight: 36 }} />
        <Box sx={{ fontSize: 13, color: "rgba(255,255,255,.5)", position: "relative" }}>© 2026 SurgeryHub · Bruxelles</Box>
      </Box>

      {/* Right — form */}
      <Box sx={{ display: "flex", alignItems: "center", justifyContent: "center", p: "48px", background: "#fff" }}>
        <Box sx={{ width: "100%", maxWidth: 400 }}>
          <Box component="h1" sx={{ m: 0, fontSize: 30, fontWeight: 800, letterSpacing: "-0.02em", color: "#16202B" }}>
            Connexion
          </Box>
          <Box component="p" sx={{ mt: "8px", mb: 0, fontSize: 15, color: GRAY_500, lineHeight: 1.5 }}>
            Accédez à votre espace personnel
          </Box>

          <Box sx={{ mt: "30px" }}>
            {fields}
            <Box sx={{ mt: "18px" }}>
              <LoginButton loading={submitting} disabled={!canSubmit} onClick={onSubmit} height={52} />
            </Box>
          </Box>

          <Box component="p" sx={{ mt: "26px", textAlign: "center", fontSize: 14, color: GRAY_500 }}>
            Vous n'avez pas de compte&nbsp;?{" "}
            <Box component="a" onClick={notAvailableYet} sx={{ color: GREEN_700, fontWeight: 700, textDecoration: "none", cursor: "pointer" }}>
              Demander une invitation
            </Box>
          </Box>
        </Box>
      </Box>
    </Box>
  );
}
