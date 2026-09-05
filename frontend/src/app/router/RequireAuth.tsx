import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";

export function RequireAuth() {
  const { state } = useAuth();
  const location = useLocation();

  if (state.status === "loading") {
    return <div style={{ padding: 16 }}>Chargement…</div>;
  }

  if (state.status === "anonymous") {
    // Correctif auth/router (2026-09-04) — inclut la query string (pathname seul avant
    // ce correctif) : un deep-link avec paramètres (ex. notification Demandes Catalogue,
    // ?kind=&requestId=) la perdait sur un chargement à froid, avant que le token stocké
    // n'ait pu être validé (state.status vaut "anonymous" de façon synchrone au tout
    // premier rendu, voir AuthContext — la validation via /api/me est asynchrone).
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  }

  return <Outlet />;
}
