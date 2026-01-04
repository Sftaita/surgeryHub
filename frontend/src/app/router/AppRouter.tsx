import { Navigate, Route, Routes } from "react-router-dom";
import { RequireAuth } from "./RequireAuth";
import { RequireAppAccess } from "./RequireAppAccess";
import { MobileLayout } from "../layouts/MobileLayout";
import { DesktopLayout } from "../layouts/DesktopLayout";
import { NoSitePage } from "../pages/NoSitePage";
import { ForbiddenPage } from "../pages/ForbiddenPage";
import { useAuth } from "../auth/AuthContext";
import { isMobileRole, isDesktopRole } from "../auth/roles";
import { useEffect } from "react";
import { useLocation, useNavigate } from "react-router-dom";

function LoginPage() {
  const { state, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  // Si on vient d'une route protégée, on la récupère
  const from = (location.state as any)?.from ?? "/";

  useEffect(() => {
    // Dès que l'utilisateur est authentifié, on quitte /login
    if (state.status === "authenticated") {
      navigate(from, { replace: true });
    }
  }, [state.status, navigate, from]);

  return (
    <div style={{ padding: 16 }}>
      <h2>Login</h2>
      <p>Status: {state.status}</p>

      <button onClick={() => login("test@test.com", "Password123!")}>
        Login (test)
      </button>
    </div>
  );
}

/**
 * Landing post-login : redirection selon rôle
 */
function PostLoginRedirect() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;

  const role = state.user.role;
  if (isMobileRole(role)) return <Navigate to="/app/m" replace />;
  if (isDesktopRole(role)) return <Navigate to="/app/d" replace />;

  return <Navigate to="/app/forbidden" replace />;
}

// Pages “placeholder” (sans métier)
function MobileHome() {
  return <div>Mobile Home (socle)</div>;
}
function DesktopHome() {
  return <div>Desktop Home (socle)</div>;
}

export function AppRouter() {
  return (
    <Routes>
      {/* Public */}
      <Route path="/login" element={<LoginPage />} />

      {/* Protected */}
      <Route element={<RequireAuth />}>
        {/* Après login, on redirige selon rôle */}
        <Route path="/" element={<PostLoginRedirect />} />

        {/* Toutes les routes /app sont protégées + contraintes socle */}
        <Route path="/app" element={<RequireAppAccess />}>
          <Route path="no-site" element={<NoSitePage />} />
          <Route path="forbidden" element={<ForbiddenPage />} />

          {/* Branches UI */}
          <Route element={<MobileLayout />}>
            <Route path="m" element={<MobileHome />} />
          </Route>

          <Route element={<DesktopLayout />}>
            <Route path="d" element={<DesktopHome />} />
          </Route>
        </Route>
      </Route>

      {/* Fallback */}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
