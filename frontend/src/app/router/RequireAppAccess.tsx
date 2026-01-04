import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import { isMobileRole, isDesktopRole } from "../auth/roles";

export function RequireAppAccess() {
  const { state } = useAuth();
  const location = useLocation();

  if (state.status !== "authenticated") {
    return <Navigate to="/login" replace />;
  }

  const { user } = state;

  // Rôle inconnu
  if (!isMobileRole(user.role) && !isDesktopRole(user.role)) {
    return (
      <div style={{ padding: 16 }}>
        <h2>Configuration invalide</h2>
        <p>Rôle inconnu: {String(user.role)}</p>
      </div>
    );
  }

  // Cas sans site (admin bootstrap)
  // Règle LOT 0 : si sites requis pour l’app, on bloque l’entrée dans /app/*
  // Ici : on autorise ADMIN/MANAGER à aller sur /app/no-site
  if (Array.isArray(user.sites) && user.sites.length === 0) {
    return <Navigate to="/app/no-site" replace />;
  }

  // Sinon OK
  return <Outlet />;
}
