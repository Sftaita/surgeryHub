import { jsx as _jsx, jsxs as _jsxs } from "react/jsx-runtime";
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
// ─── Lazy imports ────────────────────────────────────────────────────────────
// Instrumentiste
const TodayPage = React.lazy(() => import("../pages/instrumentist/TodayPage"));
const OffersPage = React.lazy(() => import("../pages/instrumentist/OffersPage"));
const MyMissionsPage = React.lazy(() => import("../pages/instrumentist/MyMissionsPage"));
const PlanningPage = React.lazy(() => import("../pages/instrumentist/PlanningPage"));
const NotificationsPage = React.lazy(() => import("../pages/instrumentist/NotificationsPage"));
const DeclareMissionPage = React.lazy(() => import("../pages/instrumentist/DeclareMissionPage"));
const MissionDetailPageI = React.lazy(() => import("../pages/instrumentist/MissionDetailPage"));
const MissionEncodingPage = React.lazy(() => import("../pages/instrumentist/MissionEncodingPage"));
// Manager
const MissionsListPage = React.lazy(() => import("../pages/manager/MissionsListPage"));
const MissionDetailPageM = React.lazy(() => import("../pages/manager/MissionDetailPage"));
const MissionCreatePage = React.lazy(() => import("../pages/manager/MissionCreatePage"));
const InstrumentistsPage = React.lazy(() => import("../pages/manager/InstrumentistsPage"));
const SurgeonsPage = React.lazy(() => import("../pages/manager/SurgeonsPage"));
const CataloguePage = React.lazy(() => import("../pages/manager/CataloguePage"));
const CatalogueRequestsPage = React.lazy(() => import("../pages/manager/CatalogueRequestsPage"));
const BillingConfigPage = React.lazy(() => import("../pages/manager/billing/BillingConfigPage"));
const PlanningTemplatesPage = React.lazy(() => import("../pages/manager/planning/PlanningTemplatesPage"));
const PlanningTemplateEditorPage = React.lazy(() => import("../pages/manager/planning/PlanningTemplateEditorPage"));
const PlanningGeneratePage = React.lazy(() => import("../pages/manager/planning/PlanningGeneratePage"));
const AbsencesPage = React.lazy(() => import("../pages/manager/planning/AbsencesPage"));
const SpecialtiesPage = React.lazy(() => import("../pages/manager/planning/SpecialtiesPage"));
const FirmInvoicesPage = React.lazy(() => import("../pages/manager/billing/FirmInvoicesPage"));
const FirmInvoiceDetailPage = React.lazy(() => import("../pages/manager/billing/FirmInvoiceDetailPage"));
const InstrumentistStatementsPage = React.lazy(() => import("../pages/manager/billing/InstrumentistStatementsPage"));
const InstrumentistStatementDetailPage = React.lazy(() => import("../pages/manager/billing/InstrumentistStatementDetailPage"));
// ─── Suspense fallback ───────────────────────────────────────────────────────
function PageLoader() {
    return (_jsx(Box, { sx: { display: "flex", justifyContent: "center", alignItems: "center", minHeight: "60vh" }, children: _jsx(CircularProgress, { size: 28 }) }));
}
// ─── Guards ──────────────────────────────────────────────────────────────────
function PostLoginRedirect() {
    const { state } = useAuth();
    if (state.status !== "authenticated")
        return _jsx(Navigate, { to: "/login", replace: true });
    const role = state.user.role;
    if (isDesktopRole(role))
        return _jsx(Navigate, { to: "/app/m/missions", replace: true });
    if (role === "SURGEON")
        return _jsx(Navigate, { to: "/app/s", replace: true });
    if (isMobileRole(role))
        return _jsx(Navigate, { to: "/app/i/today", replace: true });
    return _jsx(Navigate, { to: "/app/forbidden", replace: true });
}
function RequireInstrumentist() {
    const { state } = useAuth();
    if (state.status !== "authenticated")
        return _jsx(Navigate, { to: "/login", replace: true });
    if (state.user.role !== "INSTRUMENTIST")
        return _jsx(Navigate, { to: "/app/m/missions", replace: true });
    return _jsx(Outlet, {});
}
function RequireManager() {
    const { state } = useAuth();
    if (state.status !== "authenticated")
        return _jsx(Navigate, { to: "/login", replace: true });
    if (!isDesktopRole(state.user.role))
        return _jsx(Navigate, { to: "/app/i/today", replace: true });
    return _jsx(Outlet, {});
}
function SurgeonHome() {
    return _jsx("div", { children: "Surgeon Home" });
}
// ─── Router ──────────────────────────────────────────────────────────────────
export function AppRouter() {
    return (_jsx(React.Suspense, { fallback: _jsx(PageLoader, {}), children: _jsxs(Routes, { children: [_jsx(Route, { path: "/login", element: _jsx(LoginPage, {}) }), _jsx(Route, { path: "/complete-account", element: _jsx(CompleteAccountPage, {}) }), _jsxs(Route, { element: _jsx(RequireAuth, {}), children: [_jsx(Route, { path: "/", element: _jsx(PostLoginRedirect, {}) }), _jsxs(Route, { path: "/app", element: _jsx(RequireAppAccess, {}), children: [_jsx(Route, { path: "forbidden", element: _jsx(ForbiddenPage, {}) }), _jsx(Route, { element: _jsx(RequireInstrumentist, {}), children: _jsxs(Route, { element: _jsx(MobileLayout, {}), children: [_jsx(Route, { path: "i", element: _jsx(Navigate, { to: "/app/i/today", replace: true }) }), _jsx(Route, { path: "i/today", element: _jsx(TodayPage, {}) }), _jsx(Route, { path: "i/offers", element: _jsx(OffersPage, {}) }), _jsx(Route, { path: "i/my-missions", element: _jsx(MyMissionsPage, {}) }), _jsx(Route, { path: "i/planning", element: _jsx(PlanningPage, {}) }), _jsx(Route, { path: "i/notifications", element: _jsx(NotificationsPage, {}) }), _jsx(Route, { path: "i/missions/declare", element: _jsx(DeclareMissionPage, {}) }), _jsx(Route, { path: "i/missions/:id", element: _jsx(MissionDetailPageI, {}) }), _jsx(Route, { path: "i/missions/:id/encoding", element: _jsx(MissionEncodingPage, {}) })] }) }), _jsx(Route, { element: _jsx(MobileLayout, {}), children: _jsx(Route, { path: "s", element: _jsx(SurgeonHome, {}) }) }), _jsx(Route, { element: _jsx(RequireManager, {}), children: _jsxs(Route, { element: _jsx(DesktopLayout, {}), children: [_jsx(Route, { path: "m", element: _jsx(Navigate, { to: "/app/m/missions", replace: true }) }), _jsx(Route, { path: "m/missions", element: _jsx(MissionsListPage, {}) }), _jsx(Route, { path: "m/missions/to-validate", element: _jsx(MissionsListPage, {}) }), _jsx(Route, { path: "m/missions/new", element: _jsx(MissionCreatePage, {}) }), _jsx(Route, { path: "m/missions/:id", element: _jsx(MissionDetailPageM, {}) }), _jsx(Route, { path: "m/instrumentists", element: _jsx(InstrumentistsPage, {}) }), _jsx(Route, { path: "m/surgeons", element: _jsx(SurgeonsPage, {}) }), _jsx(Route, { path: "m/catalogue", element: _jsx(CataloguePage, {}) }), _jsx(Route, { path: "m/catalogue/requests", element: _jsx(CatalogueRequestsPage, {}) }), _jsx(Route, { path: "m/billing/config", element: _jsx(BillingConfigPage, {}) }), _jsx(Route, { path: "m/billing/firm-invoices", element: _jsx(FirmInvoicesPage, {}) }), _jsx(Route, { path: "m/billing/firm-invoices/:id", element: _jsx(FirmInvoiceDetailPage, {}) }), _jsx(Route, { path: "m/billing/statements", element: _jsx(InstrumentistStatementsPage, {}) }), _jsx(Route, { path: "m/billing/statements/:id", element: _jsx(InstrumentistStatementDetailPage, {}) }), _jsx(Route, { path: "m/planning/templates", element: _jsx(PlanningTemplatesPage, {}) }), _jsx(Route, { path: "m/planning/templates/:id", element: _jsx(PlanningTemplateEditorPage, {}) }), _jsx(Route, { path: "m/planning/generate", element: _jsx(PlanningGeneratePage, {}) }), _jsx(Route, { path: "m/planning/absences", element: _jsx(AbsencesPage, {}) }), _jsx(Route, { path: "m/planning/specialties", element: _jsx(SpecialtiesPage, {}) })] }) })] })] }), _jsx(Route, { path: "*", element: _jsx(Navigate, { to: "/", replace: true }) })] }) }));
}
