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
import { isDesktopRole, homePathForRole } from "../auth/roles";
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

// Partagé instrumentiste + chirurgien (socle mobile Lot 1, 2026-08-05) — jamais de
// SurgeonProfilePage/SurgeonNotificationsPage quasi identiques, voir docs/decisions.md.
const ProfilePage         = React.lazy(() => import("../pages/common/ProfilePage"));

// Chirurgien (Lot 2, D-095) — Home/Planning réels ; détail mission réutilise
// MissionDetailPageI (même composant que l'instrumentiste, allowedActions-driven).
const SurgeonHomePage     = React.lazy(() => import("../pages/surgeon/SurgeonHomePage"));
const SurgeonPlanningPage = React.lazy(() => import("../pages/surgeon/SurgeonPlanningPage"));
// « Salles libérées » (Lot D, post D-114) — vue chirurgien, scopée à ses affiliations.
const SurgeonAvailableRoomsPage = React.lazy(() => import("../pages/surgeon/SurgeonAvailableRoomsPage"));

// Partagé instrumentiste + chirurgien (Lot 3, D-097) — un seul SelfAbsencesPage, jamais
// SurgeonAbsencesPage/InstrumentistAbsencesPage, voir docs/decisions.md.
const SelfAbsencesPage    = React.lazy(() => import("../features/self-absences/SelfAbsencesPage"));

// Chirurgien (Lot 4, D-098) — activité personnelle + podium (pas un classement inter-
// chirurgiens), voir docs/decisions.md.
const SurgeonActivityPage = React.lazy(() => import("../features/surgeon-activity/SurgeonActivityPage"));

// Chirurgien (Lot 5, D-099) — demande de mission (intention chirurgien, jamais une
// Mission directement, voir docs/decisions.md).
const SurgeonMissionRequestFormPage = React.lazy(() => import("../features/surgeon-mission-requests/SurgeonMissionRequestFormPage"));
const SurgeonMissionRequestsPage    = React.lazy(() => import("../features/surgeon-mission-requests/SurgeonMissionRequestsPage"));

// Chirurgien (Lot 6, D-100) — consultation lecture seule de l'encodage instrumentiste +
// signalement d'anomalie, jamais un réemploi de la page d'édition instrumentiste (voir
// docs/decisions.md).
const SurgeonEncodingPage = React.lazy(() => import("../features/surgeon-encoding/SurgeonEncodingPage"));

// Admin
const AdminUsersPage       = React.lazy(() => import("../pages/admin/AdminUsersPage"));
const AdminSitesPage       = React.lazy(() => import("../pages/admin/AdminSitesPage"));
const AdminInvitationsPage = React.lazy(() => import("../pages/admin/AdminInvitationsPage"));
const AdminAuditPage       = React.lazy(() => import("../pages/admin/AdminAuditPage"));
const AdminOutboundNotificationsPage = React.lazy(() => import("../pages/admin/AdminOutboundNotificationsPage"));

// Manager
const DashboardPage          = React.lazy(() => import("../pages/manager/DashboardPage"));
const ProfilePageM           = React.lazy(() => import("../pages/manager/ProfilePage"));
const NotificationsPageM     = React.lazy(() => import("../pages/manager/NotificationsPage"));
const MissionsListPage       = React.lazy(() => import("../pages/manager/MissionsListPage"));
const MissionDetailPageM     = React.lazy(() => import("../pages/manager/MissionDetailPage"));
const MissionCreatePage      = React.lazy(() => import("../pages/manager/MissionCreatePage"));
const InstrumentistsPage     = React.lazy(() => import("../pages/manager/InstrumentistsPage"));
const SurgeonsPage           = React.lazy(() => import("../pages/manager/SurgeonsPage"));
const CataloguePage                     = React.lazy(() => import("../pages/manager/CataloguePage"));
const CatalogueRequestsPage             = React.lazy(() => import("../pages/manager/CatalogueRequestsPage"));
const HospitalsPage                     = React.lazy(() => import("../pages/manager/HospitalsPage"));
const FirmsPage                         = React.lazy(() => import("../pages/manager/FirmsPage"));
const InterventionTypesPage             = React.lazy(() => import("../pages/manager/InterventionTypesPage"));
const PrestationsPage                   = React.lazy(() => import("../pages/manager/PrestationsPage"));
const EncodingTrackingPage              = React.lazy(() => import("../pages/manager/billing/EncodingTrackingPage"));
const FirmInvoicesPage                  = React.lazy(() => import("../pages/manager/billing/FirmInvoicesPage"));
const FirmInvoiceDetailPage             = React.lazy(() => import("../pages/manager/billing/FirmInvoiceDetailPage"));
const InstrumentistStatementsPage       = React.lazy(() => import("../pages/manager/billing/InstrumentistStatementsPage"));
const InstrumentistStatementDetailPage  = React.lazy(() => import("../pages/manager/billing/InstrumentistStatementDetailPage"));
const CorrectionDetailPage              = React.lazy(() => import("../pages/manager/billing/CorrectionDetailPage"));
const FinancialStatisticsPage           = React.lazy(() => import("../pages/manager/FinancialStatisticsPage"));
const AbsencesPage                      = React.lazy(() => import("../pages/manager/planning/AbsencesPage"));
const PlanningV2Page                    = React.lazy(() => import("../pages/manager/planning/PlanningV2Page"));
const PlanningSchedulePage              = React.lazy(() => import("../pages/manager/planning/PlanningSchedulePage"));

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
  return <Navigate to={homePathForRole(state.user.role)} replace />;
}

function RequireInstrumentist() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  if (state.user.role !== "INSTRUMENTIST") return <Navigate to={homePathForRole(state.user.role)} replace />;
  return <Outlet />;
}

function RequireSurgeon() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  if (state.user.role !== "SURGEON") return <Navigate to={homePathForRole(state.user.role)} replace />;
  return <Outlet />;
}

function RequireManager() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  if (!isDesktopRole(state.user.role)) return <Navigate to={homePathForRole(state.user.role)} replace />;
  return <Outlet />;
}

function RequireAdmin() {
  const { state } = useAuth();
  if (state.status !== "authenticated") return <Navigate to="/login" replace />;
  if (state.user.role !== "ADMIN") return <Navigate to="/app/forbidden" replace />;
  return <Outlet />;
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
                <Route path="i/absences" element={<SelfAbsencesPage />} />
                <Route path="i/missions/declare" element={<DeclareMissionPage />} />
                <Route path="i/missions/:id" element={<MissionDetailPageI />} />
                <Route path="i/missions/:id/encoding" element={<MissionEncodingPage />} />
              </Route>
            </Route>

            {/* Surgeon — même MobileLayout que l'instrumentiste (jamais un second layout,
                voir docs/decisions.md). Home/Planning/Activité/Absences/Demandes sont
                réellement fonctionnels depuis les Lots 2 à 5 (D-095 à D-099). */}
            <Route element={<RequireSurgeon />}>
              <Route element={<MobileLayout />}>
                <Route path="s" element={<SurgeonHomePage />} />
                <Route path="s/planning" element={<SurgeonPlanningPage />} />
                <Route path="s/planning/salles-disponibles" element={<SurgeonAvailableRoomsPage />} />
                <Route path="s/activity" element={<SurgeonActivityPage />} />
                <Route path="s/requests" element={<SurgeonMissionRequestsPage />} />
                <Route path="s/mission-requests/new" element={<SurgeonMissionRequestFormPage />} />
                <Route path="s/absences" element={<SelfAbsencesPage />} />
                <Route path="s/notifications" element={<NotificationsPage />} />
                <Route path="s/profile" element={<ProfilePage />} />
                <Route path="s/missions/:id" element={<MissionDetailPageI />} />
                <Route path="s/missions/:id/encoding" element={<SurgeonEncodingPage />} />
              </Route>
            </Route>

            {/* Manager / Admin */}
            <Route element={<RequireManager />}>
              <Route element={<DesktopLayout />}>
                <Route path="m" element={<Navigate to="/app/m/dashboard" replace />} />
                <Route path="m/dashboard" element={<DashboardPage />} />
                <Route path="m/profile" element={<ProfilePageM />} />
                <Route path="m/notifications" element={<NotificationsPageM />} />
                <Route path="m/missions" element={<MissionsListPage />} />
                <Route path="m/missions/to-validate" element={<MissionsListPage />} />
                <Route path="m/missions/requests" element={<MissionsListPage />} />
                <Route path="m/missions/new" element={<MissionCreatePage />} />
                <Route path="m/missions/:id" element={<MissionDetailPageM />} />
                <Route path="m/instrumentists" element={<InstrumentistsPage />} />
                <Route path="m/surgeons" element={<SurgeonsPage />} />
                <Route path="m/hospitals" element={<HospitalsPage />} />
                <Route path="m/firms" element={<FirmsPage />} />
                <Route path="m/intervention-types" element={<InterventionTypesPage />} />
                <Route path="m/catalogue" element={<CataloguePage />} />
                <Route path="m/catalogue/prestations" element={<PrestationsPage />} />
                <Route path="m/catalogue/requests" element={<CatalogueRequestsPage />} />
                <Route path="m/billing/encodings" element={<EncodingTrackingPage />} />
                <Route path="m/billing/config" element={<Navigate to="/app/m/catalogue/prestations" replace />} />
                <Route path="m/billing/firm-invoices" element={<FirmInvoicesPage />} />
                <Route path="m/billing/firm-invoices/:id" element={<FirmInvoiceDetailPage />} />
                <Route path="m/billing/statements" element={<InstrumentistStatementsPage />} />
                <Route path="m/billing/statements/:id" element={<InstrumentistStatementDetailPage />} />
                <Route path="m/billing/firm-invoice-corrections/:id" element={<CorrectionDetailPage resource="firm-invoices" />} />
                <Route path="m/billing/instrumentist-statement-corrections/:id" element={<CorrectionDetailPage resource="instrumentist-statements" />} />
                <Route path="m/finance/statistics" element={<FinancialStatisticsPage />} />
                <Route path="m/planning/absences" element={<AbsencesPage />} />
                <Route path="m/planning" element={<Navigate to="/app/m/planning/v2" replace />} />
                <Route path="m/planning/v2" element={<PlanningV2Page />} />
                <Route path="m/planning/living" element={<PlanningSchedulePage />} />
              </Route>
            </Route>

            {/* Admin */}
            <Route element={<RequireAdmin />}>
              <Route element={<DesktopLayout />}>
                <Route path="admin/users"       element={<AdminUsersPage />} />
                <Route path="admin/sites"       element={<AdminSitesPage />} />
                <Route path="admin/invitations" element={<AdminInvitationsPage />} />
                <Route path="admin/audit"       element={<AdminAuditPage />} />
                {/* D-084 — entrée de menu branchée dans DesktopLayout.tsx ("Historique des notifications"). */}
                <Route path="admin/outbound-notifications" element={<AdminOutboundNotificationsPage />} />
              </Route>
            </Route>
          </Route>
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </React.Suspense>
  );
}
