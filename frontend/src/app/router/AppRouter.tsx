import { Navigate, Route, Routes, Outlet } from "react-router-dom";

import { RequireAuth } from "./RequireAuth";
import { RequireAppAccess } from "./RequireAppAccess";

import { MobileLayout } from "../layouts/MobileLayout";
import { DesktopLayout } from "../layouts/DesktopLayout";

import { ForbiddenPage } from "../pages/ForbiddenPage";
import LoginPage from "../pages/LoginPage";
import { useAuth } from "../auth/AuthContext";
import { isMobileRole, isDesktopRole } from "../auth/roles";

// Lot 1 — Manager/Admin — Missions
import MissionsListPage from "../pages/manager/MissionsListPage";
import MissionDetailPage from "../pages/manager/MissionDetailPage";

// Lot 2b — Manager/Admin — Create mission
import MissionCreatePage from "../pages/manager/MissionCreatePage";

// Lot 3 — Instrumentist (mobile-first)
import OffersPage from "../pages/instrumentist/OffersPage";
import MyMissionsPage from "../pages/instrumentist/MyMissionsPage";
import MissionDetailPageInstrumentist from "../pages/instrumentist/MissionDetailPage";

// Lot 4 — Instrumentist (mobile-first) — Encoding
import MissionEncodingPage from "../pages/instrumentist/MissionEncodingPage";

// Lot F2 — Instrumentist — Declare mission
import DeclareMissionPage from "../pages/instrumentist/DeclareMissionPage";

function PostLoginRedirect() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;

  const role = state.user.role;

  // ✅ Manager / Admin → va directement sur la liste missions
  if (isDesktopRole(role)) return <Navigate to="/app/m/missions" replace />;

  // Instrumentist / Surgeon
  if (role === "SURGEON") return <Navigate to="/app/s" replace />;
  if (isMobileRole(role)) return <Navigate to="/app/i/offers" replace />;

  return <Navigate to="/app/forbidden" replace />;
}

// ✅ Guard routes instrumentiste
function RequireInstrumentist() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  const role = state.user.role;
  if (role !== "INSTRUMENTIST")
    return <Navigate to="/app/m/missions" replace />;
  return <Outlet />;
}

// ✅ Guard routes manager/admin
function RequireManager() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  const role = state.user.role;
  if (!isDesktopRole(role)) return <Navigate to="/app/i/offers" replace />;
  return <Outlet />;
}

// Placeholders socle
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
          <Route element={<RequireInstrumentist />}>
            <Route element={<MobileLayout />}>
              <Route
                path="i"
                element={<Navigate to="/app/i/offers" replace />}
              />
              <Route path="i/offers" element={<OffersPage />} />
              <Route path="i/my-missions" element={<MyMissionsPage />} />

              {/* Lot F2 — Declare mission */}
              <Route
                path="i/missions/declare"
                element={<DeclareMissionPage />}
              />

              <Route
                path="i/missions/:id"
                element={<MissionDetailPageInstrumentist />}
              />
              <Route
                path="i/missions/:id/encoding"
                element={<MissionEncodingPage />}
              />
            </Route>
          </Route>

          {/* Surgeon */}
          <Route element={<MobileLayout />}>
            <Route path="s" element={<SurgeonHome />} />
          </Route>

          {/* Manager / Admin */}
          <Route element={<RequireManager />}>
            <Route element={<DesktopLayout />}>
              {/* ✅ /app/m redirige sur la liste */}
              <Route
                path="m"
                element={<Navigate to="/app/m/missions" replace />}
              />

              {/* Lot 1 — Missions */}
              <Route path="m/missions" element={<MissionsListPage />} />

              {/* Lot 2b — Missions — Create */}
              <Route path="m/missions/new" element={<MissionCreatePage />} />

              <Route path="m/missions/:id" element={<MissionDetailPage />} />
            </Route>
          </Route>
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
