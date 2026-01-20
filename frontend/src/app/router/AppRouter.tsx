import {
  Navigate,
  Route,
  Routes,
  useLocation,
  useNavigate,
} from "react-router-dom";
import { useEffect } from "react";

import { RequireAuth } from "./RequireAuth";
import { RequireAppAccess } from "./RequireAppAccess";

import { MobileLayout } from "../layouts/MobileLayout";
import { DesktopLayout } from "../layouts/DesktopLayout";

import { ForbiddenPage } from "../pages/ForbiddenPage";
import { useAuth } from "../auth/AuthContext";
import { isMobileRole, isDesktopRole } from "../auth/roles";

// Lot 1 — Manager/Admin — Missions
import MissionsListPage from "../pages/manager/MissionsListPage";
import MissionDetailPage from "../pages/manager/MissionDetailPage";

// Lot 2b — Manager/Admin — Create mission
import MissionCreatePage from "../pages/manager/MissionCreatePage";

function LoginPage() {
  const { state, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as any)?.from ?? "/";

  useEffect(() => {
    if (state.status === "authenticated") {
      navigate(from, { replace: true });
    }
  }, [state.status, navigate, from]);

  return (
    <div style={{ padding: 16 }}>
      <h2>Login</h2>
      <button onClick={() => login("test@test.com", "Password123!")}>
        Login (test)
      </button>
    </div>
  );
}

function PostLoginRedirect() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;

  const role = state.user.role;

  // Manager / Admin
  if (isDesktopRole(role)) return <Navigate to="/app/m" replace />;

  // Instrumentist / Surgeon
  if (role === "SURGEON") return <Navigate to="/app/s" replace />;
  if (isMobileRole(role)) return <Navigate to="/app/i" replace />;

  return <Navigate to="/app/forbidden" replace />;
}

// Placeholders socle
function InstrumentistHome() {
  return <div>Instrumentist Home</div>;
}
function SurgeonHome() {
  return <div>Surgeon Home</div>;
}
function ManagerHome() {
  return <div>Manager / Admin Home</div>;
}

export function AppRouter() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route element={<RequireAuth />}>
        <Route path="/" element={<PostLoginRedirect />} />

        <Route path="/app" element={<RequireAppAccess />}>
          <Route path="forbidden" element={<ForbiddenPage />} />

          {/* Instrumentist */}
          <Route element={<MobileLayout />}>
            <Route path="i" element={<InstrumentistHome />} />
          </Route>

          {/* Surgeon */}
          <Route element={<MobileLayout />}>
            <Route path="s" element={<SurgeonHome />} />
          </Route>

          {/* Manager / Admin */}
          <Route element={<DesktopLayout />}>
            <Route path="m" element={<ManagerHome />} />

            {/* Lot 1 — Missions */}
            <Route path="m/missions" element={<MissionsListPage />} />

            {/* Lot 2b — Missions — Create */}
            <Route path="m/missions/new" element={<MissionCreatePage />} />

            <Route path="m/missions/:id" element={<MissionDetailPage />} />
          </Route>
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
