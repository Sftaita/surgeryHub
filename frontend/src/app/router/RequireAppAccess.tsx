import { Navigate, Outlet } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import { isMobileRole, isDesktopRole } from "../auth/roles";

export function RequireAppAccess() {
  const { state } = useAuth();

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

  // IMPORTANT:
  // Un site n’est pas obligatoire → on ne bloque pas l’accès à /app/*
  // (suppression de la redirection /app/no-site qui provoquait une boucle)

  return <Outlet />;
}
