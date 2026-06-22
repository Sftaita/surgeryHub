import * as React from "react";
import { Navigate, Route, Routes, Outlet } from "react-router-dom";
import { CircularProgress, Box } from "@mui/material";

import { RequireAuth } from "./RequireAuth";
import { RequireAppAccess } from "./RequireAppAccess";

import { MobileLayout } from "../layouts/MobileLayout";
import { DesktopLayout } from "../layouts/DesktopLayout";

import { ForbiddenPage } from "../pages/ForbiddenPage";
import LoginPage from "../pages/LoginPage";
import { useAuth } from "../auth/AuthContext";
import { isMobileRole, isDesktopRole } from "../auth/roles";
import CompleteAccountPage from "../pages/CompleteAccountPage";
import LandingPage from "../pages/LandingPage";

// ─── Lazy imports ────────────────────────────────────────────────────────────

// Instrumentiste
const TodayPage           = React.lazy(() => import("../pages/instrumentist/TodayPage"));
const OffersPage          = React.lazy(() => import("../pages/instrumentist/OffersPage"));
const MyMissionsPage      = React.lazy(() => import("../pages/instrumentist/MyMissionsPage"));
const PlanningPage        = React.lazy(() => import("../pages/instrumentist/PlanningPage"));
const NotificationsPage   = React.lazy(() => import("../pages/instrumentist/NotificationsPage"));
const DeclareMissionPage  = React.lazy(() => import("../pages/instrumentist/DeclareMissionPage"));
const MissionDetailPageI  = React.lazy(() => import("../pages/instrumentist/MissionDetailPage"));
const MissionEncodingPage = React.lazy(() => import("../pages/instrumentist/MissionEncodingPage"));
const ProfilePage         = React.lazy(() => import("../pages/instrumentist/ProfilePage"));

// Admin
const AdminUsersPage       = React.lazy(() => import("../pages/admin/AdminUsersPage"));
const AdminSitesPage       = React.lazy(() => import("../pages/admin/AdminSitesPage"));
const AdminInvitationsPage = React.lazy(() => import("../pages/admin/AdminInvitationsPage"));
const AdminAuditPage       = React.lazy(() => import("../pages/admin/AdminAuditPage"));

// Manager
const MissionsListPage       = React.lazy(() => import("../pages/manager/MissionsListPage"));
const MissionDetailPageM     = React.lazy(() => import("../pages/manager/MissionDetailPage"));
const MissionCreatePage      = React.lazy(() => import("../pages/manager/MissionCreatePage"));
const InstrumentistsPage     = React.lazy(() => import("../pages/manager/InstrumentistsPage"));
const SurgeonsPage           = React.lazy(() => import("../pages/manager/SurgeonsPage"));
const CataloguePage                     = React.lazy(() => import("../pages/manager/CataloguePage"));
const CatalogueRequestsPage             = React.lazy(() => import("../pages/manager/CatalogueRequestsPage"));
const HospitalsPage                     = React.lazy(() => import("../pages/manager/HospitalsPage"));
const FirmsPage                         = React.lazy(() => import("../pages/manager/FirmsPage"));
const FirmInvoicesPage                  = React.lazy(() => import("../pages/manager/billing/FirmInvoicesPage"));
const FirmInvoiceDetailPage             = React.lazy(() => import("../pages/manager/billing/FirmInvoiceDetailPage"));
const InstrumentistStatementsPage       = React.lazy(() => import("../pages/manager/billing/InstrumentistStatementsPage"));
const InstrumentistStatementDetailPage  = React.lazy(() => import("../pages/manager/billing/InstrumentistStatementDetailPage"));
const BillingConfigPage                 = React.lazy(() => import("../pages/manager/billing/BillingConfigPage"));
const PlanningTemplatesPage             = React.lazy(() => import("../pages/manager/planning/PlanningTemplatesPage"));
const PlanningTemplateEditorPage        = React.lazy(() => import("../pages/manager/planning/PlanningTemplateEditorPage"));
const PlanningGeneratePage              = React.lazy(() => import("../pages/manager/planning/PlanningGeneratePage"));
const PlanningSchedulePage              = React.lazy(() => import("../pages/manager/planning/PlanningSchedulePage"));
const AbsencesPage                      = React.lazy(() => import("../pages/manager/planning/AbsencesPage"));
const SpecialtiesPage                   = React.lazy(() => import("../pages/manager/planning/SpecialtiesPage"));
const PlanningVersionsListPage          = React.lazy(() => import("../pages/manager/planning/PlanningVersionsListPage"));
const PlanningVersionDetailPage         = React.lazy(() => import("../pages/manager/planning/PlanningVersionDetailPage"));
const PlanningV2Page                    = React.lazy(() => import("../pages/manager/planning/PlanningV2Page"));

// ─── Suspense fallback ───────────────────────────────────────────────────────

function PageLoader() {
  return (
    <Box sx={{ display: "flex", justifyContent: "center", alignItems: "center", minHeight: "60vh" }}>
      <CircularProgress size={28} />
    </Box>
  );
}

// ─── Guards ──────────────────────────────────────────────────────────────────

function PostLoginRedirect() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  const role = state.user.role;
  if (isDesktopRole(role)) return <Navigate to="/app/m/missions" replace />;
  if (role === "SURGEON") return <Navigate to="/app/s" replace />;
  if (isMobileRole(role)) return <Navigate to="/app/i/today" replace />;
  return <Navigate to="/app/forbidden" replace />;
}

function RequireInstrumentist() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  if (state.user.role !== "INSTRUMENTIST") return <Navigate to="/app/m/missions" replace />;
  return <Outlet />;
}

function RequireManager() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  if (!isDesktopRole(state.user.role)) return <Navigate to="/app/i/today" replace />;
  return <Outlet />;
}

function RequireAdmin() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  if (state.user.role !== "ADMIN") return <Navigate to="/app/forbidden" replace />;
  return <Outlet />;
}

function SurgeonHome() {
  return <div>Surgeon Home</div>;
}

// ─── Router ──────────────────────────────────────────────────────────────────

export function AppRouter() {
  return (
    <React.Suspense fallback={<PageLoader />}>
      <Routes>
        <Route path="/" element={<LandingPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/complete-account" element={<CompleteAccountPage />} />

        <Route element={<RequireAuth />}>
          <Route path="/app" element={<RequireAppAccess />}>
            <Route index element={<PostLoginRedirect />} />
            <Route path="forbidden" element={<ForbiddenPage />} />

            {/* Instrumentiste */}
            <Route element={<RequireInstrumentist />}>
              <Route element={<MobileLayout />}>
                <Route path="i" element={<Navigate to="/app/i/today" replace />} />
                <Route path="i/today" element={<TodayPage />} />
                <Route path="i/offers" element={<OffersPage />} />
                <Route path="i/my-missions" element={<MyMissionsPage />} />
                <Route path="i/planning" element={<PlanningPage />} />
                <Route path="i/notifications" element={<NotificationsPage />} />
                <Route path="i/profile" element={<ProfilePage />} />
                <Route path="i/missions/declare" element={<DeclareMissionPage />} />
                <Route path="i/missions/:id" element={<MissionDetailPageI />} />
                <Route path="i/missions/:id/encoding" element={<MissionEncodingPage />} />
              </Route>
            </Route>

            {/* Surgeon */}
            <Route element={<MobileLayout />}>
              <Route path="s" element={<SurgeonHome />} />
            </Route>

            {/* Manager / Admin */}
            <Route element={<RequireManager />}>
              <Route element={<DesktopLayout />}>
                <Route path="m" element={<Navigate to="/app/m/missions" replace />} />
                <Route path="m/missions" element={<MissionsListPage />} />
                <Route path="m/missions/to-validate" element={<MissionsListPage />} />
                <Route path="m/missions/new" element={<MissionCreatePage />} />
                <Route path="m/missions/:id" element={<MissionDetailPageM />} />
                <Route path="m/instrumentists" element={<InstrumentistsPage />} />
                <Route path="m/surgeons" element={<SurgeonsPage />} />
                <Route path="m/hospitals" element={<HospitalsPage />} />
                <Route path="m/firms" element={<FirmsPage />} />
                <Route path="m/catalogue" element={<CataloguePage />} />
                <Route path="m/catalogue/requests" element={<CatalogueRequestsPage />} />
                <Route path="m/billing/config" element={<BillingConfigPage />} />
                <Route path="m/billing/firm-invoices" element={<FirmInvoicesPage />} />
                <Route path="m/billing/firm-invoices/:id" element={<FirmInvoiceDetailPage />} />
                <Route path="m/billing/statements" element={<InstrumentistStatementsPage />} />
                <Route path="m/billing/statements/:id" element={<InstrumentistStatementDetailPage />} />
                <Route path="m/planning/templates" element={<PlanningTemplatesPage />} />
                <Route path="m/planning/templates/:id" element={<PlanningTemplateEditorPage />} />
                <Route path="m/planning/generate" element={<PlanningGeneratePage />} />
                <Route path="m/planning/schedule" element={<PlanningSchedulePage />} />
                <Route path="m/planning/absences" element={<AbsencesPage />} />
                <Route path="m/planning/specialties" element={<SpecialtiesPage />} />
                <Route path="m/planning/versions" element={<PlanningVersionsListPage />} />
                <Route path="m/planning/versions/:id" element={<PlanningVersionDetailPage />} />
                {/* Planning V2 (Batch 10) is now the official manager planning UI (Batch 13
                    cutover) — V1 routes above are kept reachable by direct URL only, for
                    admin fallback/rollback, no longer linked from the sidebar. */}
                <Route path="m/planning" element={<Navigate to="/app/m/planning/v2" replace />} />
                <Route path="m/planning/v2" element={<PlanningV2Page />} />
              </Route>
            </Route>

            {/* Admin */}
            <Route element={<RequireAdmin />}>
              <Route element={<DesktopLayout />}>
                <Route path="admin/users"       element={<AdminUsersPage />} />
                <Route path="admin/sites"       element={<AdminSitesPage />} />
                <Route path="admin/invitations" element={<AdminInvitationsPage />} />
                <Route path="admin/audit"       element={<AdminAuditPage />} />
              </Route>
            </Route>
          </Route>
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </React.Suspense>
  );
}
