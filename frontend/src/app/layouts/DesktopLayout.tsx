import { useState } from "react";
import type { ReactNode } from "react";
import { Outlet, NavLink, useNavigate } from "react-router-dom";
import {
  Badge,
  Box,
  Divider,
  Drawer,
  List,
  ListItemButton,
  ListItemText,
  Menu,
  MenuItem,
  Stack,
  Typography,
} from "@mui/material";
import DashboardOutlinedIcon from "@mui/icons-material/DashboardOutlined";
import AssignmentOutlinedIcon from "@mui/icons-material/AssignmentOutlined";
import BadgeOutlinedIcon from "@mui/icons-material/BadgeOutlined";
import PersonOutlineOutlinedIcon from "@mui/icons-material/PersonOutlineOutlined";
import ApartmentOutlinedIcon from "@mui/icons-material/ApartmentOutlined";
import BusinessOutlinedIcon from "@mui/icons-material/BusinessOutlined";
import SellOutlinedIcon from "@mui/icons-material/SellOutlined";
import MoveToInboxOutlinedIcon from "@mui/icons-material/MoveToInboxOutlined";
import CalendarMonthOutlinedIcon from "@mui/icons-material/CalendarMonthOutlined";
import EventBusyOutlinedIcon from "@mui/icons-material/EventBusyOutlined";
import ReceiptLongOutlinedIcon from "@mui/icons-material/ReceiptLongOutlined";
import ReceiptOutlinedIcon from "@mui/icons-material/ReceiptOutlined";
import BarChartOutlinedIcon from "@mui/icons-material/BarChartOutlined";
import PeopleAltOutlinedIcon from "@mui/icons-material/PeopleAltOutlined";
import MailOutlineIcon from "@mui/icons-material/MailOutline";
import FactCheckOutlinedIcon from "@mui/icons-material/FactCheckOutlined";
import HistoryOutlinedIcon from "@mui/icons-material/HistoryOutlined";
import ExpandMoreIcon from "@mui/icons-material/ExpandMore";
import LogoutIcon from "@mui/icons-material/Logout";
import NotificationsActiveOutlinedIcon from "@mui/icons-material/NotificationsActiveOutlined";
import GetAppOutlinedIcon from "@mui/icons-material/GetAppOutlined";
import type { SvgIconComponent } from "@mui/icons-material";
import { useAuth } from "../auth/AuthContext";
import { usePushNotifications } from "../features/push/usePushNotifications";
import { usePwaInstallMenuState } from "../features/pwa-install/usePwaInstallMenuState";
import { getMaterialRequests } from "../features/manager-catalogue/api/catalogue.api";
import { getInterventionTypeRequests } from "../features/manager-catalogue/api/interventionTypeRequests.api";
import { fetchUnreadNotificationsCount } from "../features/notifications/api/notifications.api";
import { useNavBadgeCount } from "../ui/hooks/useNavBadgeCount";
import { dvh } from "../ui/dvh";
import { PersonAvatar } from "../ui/avatar/PersonAvatar";
import { resolveApiAssetUrl } from "../api/apiAssetUrl";

/**
 * Tokens sourced from docs/design/design-tokens.json + docs/design/components/navigation.md
 * ("Sidebar (desktop ≥ 900px)") — the project's authoritative design system, not the ad-hoc
 * handoff reference used in the first pass of D-079 (source of the "horrible" sidebar look).
 * Nav *content* stays manager-specific (Dashboard/Missions/... vs the instrumentiste app's own
 * items) — only the sizing/color/spacing rules are shared across the whole product.
 */
const NAV_WIDTH = 248; // size.sidebarWidth
const ITEM_HEIGHT = 48; // size.sidebarItem

const COLOR = {
  gray900: "#16202B",
  gray600: "#566270",
  gray500: "#727E8C",
  gray400: "#98A2AE",
  gray75: "#F1F4F7",
  green800: "#1F6B4F",
  green700: "#2C7D5F",
  green600: "#338F6E",
  green100: "#DDF4EA",
  green50: "#EFFAF5",
  red600: "#E5484D",
  red50: "#FDEEEE",
  borderSubtle: "#E7EBEF",
} as const;

function navLinkSx(isActive: boolean) {
  return {
    display: "flex",
    alignItems: "center",
    height: ITEM_HEIGHT,
    px: "12px",
    borderRadius: "13px",
    fontSize: "14px",
    fontWeight: isActive ? 800 : 600,
    color: isActive ? COLOR.green800 : COLOR.gray600,
    bgcolor: isActive ? COLOR.green50 : "transparent",
    "&:hover": { bgcolor: isActive ? COLOR.green50 : COLOR.gray75 },
  } as const;
}

const sectionHeaderSx = {
  m: "14px 0px 4px", px: "12px",
  fontSize: "11px", fontWeight: 800, letterSpacing: "0.06em",
  textTransform: "uppercase" as const, color: COLOR.gray400,
};

/** NavLink + ListItemButton composed via the render-prop pattern — drives MUI's own
 * `selected` styling from react-router's `isActive` explicitly, rather than relying on
 * NavLink's automatic `active` CSS class surviving MUI's `component` prop forwarding
 * (unverified for this MUI version). */
function NavItem({ href, icon: Icon, children }: { href: string; icon: SvgIconComponent; children: ReactNode }) {
  return (
    <NavLink to={href} style={{ textDecoration: "none" }}>
      {({ isActive }) => (
        <ListItemButton disableGutters selected={isActive} sx={navLinkSx(isActive)}>
          <Icon sx={{ fontSize: 19, mr: "10px", color: isActive ? COLOR.green800 : COLOR.gray500 }} />
          {children}
        </ListItemButton>
      )}
    </NavLink>
  );
}

const NAV_ITEMS = [
  { label: "Dashboard", href: "/app/m/dashboard", icon: DashboardOutlinedIcon },
  { label: "Missions", href: "/app/m/missions", icon: AssignmentOutlinedIcon },
  { label: "Instrumentistes", href: "/app/m/instrumentists", icon: BadgeOutlinedIcon },
  { label: "Chirurgiens", href: "/app/m/surgeons", icon: PersonOutlineOutlinedIcon },
  { label: "Établissements", href: "/app/m/hospitals", icon: ApartmentOutlinedIcon },
  { label: "Notifications", href: "/app/m/notifications", icon: NotificationsActiveOutlinedIcon },
  {
    label: "Catalogue",
    children: [
      { label: "Firmes", href: "/app/m/firms", icon: BusinessOutlinedIcon },
      { label: "Prestations", href: "/app/m/catalogue/prestations", icon: SellOutlinedIcon },
      { label: "Demandes", href: "/app/m/catalogue/requests", icon: MoveToInboxOutlinedIcon },
    ],
  },
  {
    label: "Planning",
    children: [
      { label: "Construire", href: "/app/m/planning/v2", icon: CalendarMonthOutlinedIcon },
      { label: "Absences", href: "/app/m/planning/absences", icon: EventBusyOutlinedIcon },
    ],
  },
  {
    label: "Facturation",
    children: [
      { label: "Factures Firmes", href: "/app/m/billing/firm-invoices", icon: ReceiptLongOutlinedIcon },
      { label: "Décomptes", href: "/app/m/billing/statements", icon: ReceiptOutlinedIcon },
      { label: "Statistiques", href: "/app/m/finance/statistics", icon: BarChartOutlinedIcon },
    ],
  },
] as const;

const ADMIN_ITEMS = [
  { label: "Utilisateurs", href: "/app/admin/users", icon: PeopleAltOutlinedIcon },
  { label: "Sites", href: "/app/admin/sites", icon: ApartmentOutlinedIcon },
  { label: "Invitations", href: "/app/admin/invitations", icon: MailOutlineIcon },
  { label: "Audit", href: "/app/admin/audit", icon: FactCheckOutlinedIcon },
  { label: "Historique des notifications", href: "/app/admin/outbound-notifications", icon: HistoryOutlinedIcon },
] as const;

const ROLE_LABEL: Record<string, string> = {
  MANAGER: "Manager",
  ADMIN: "Administrateur",
};

export function DesktopLayout() {
  const navigate = useNavigate();
  const { state, logout } = useAuth();
  const isAuthenticated = state.status === "authenticated";
  const { status: pushStatus, subscribe: subscribeToPush } = usePushNotifications();
  const pwaInstall = usePwaInstallMenuState();

  const [menuAnchor, setMenuAnchor] = useState<null | HTMLElement>(null);
  const menuOpen = Boolean(menuAnchor);

  const pendingMaterialCount = useNavBadgeCount(
    ["material-requests", "PENDING"],
    async () => (await getMaterialRequests({ status: "PENDING" })).items.length,
  );
  const pendingInterventionCount = useNavBadgeCount(
    ["intervention-type-requests", "PENDING"],
    async () => (await getInterventionTypeRequests({ status: "PENDING" })).items.length,
  );
  const pendingCount = pendingMaterialCount + pendingInterventionCount;
  const unreadNotificationsCount = useNavBadgeCount(
    ["notifications", "unread-count"],
    fetchUnreadNotificationsCount,
  );

  const fullName = isAuthenticated
    ? `${state.user.firstname ?? ""} ${state.user.lastname ?? ""}`.trim() || "Mon compte"
    : "";

  return (
    <Box sx={{ display: "flex", ...dvh("minHeight", "100vh") }}>
      <Drawer
        variant="permanent"
        sx={{
          width: NAV_WIDTH,
          flexShrink: 0,
          "& .MuiDrawer-paper": {
            width: NAV_WIDTH,
            boxSizing: "border-box",
            borderRight: "1px solid",
            borderColor: "divider",
          },
        }}
      >
        <Stack sx={{ height: "100%" }} direction="column" justifyContent="space-between">
          <Box sx={{ pt: "24px", px: "14px", pb: "14px" }}>
            <Box sx={{ px: "8px", pb: "18px" }}>
              <Typography variant="subtitle2" fontWeight={700} color="primary">
                SurgicalHub
              </Typography>
            </Box>
            <Divider sx={{ mb: "10px" }} />

            <List sx={{ p: 0, display: "flex", flexDirection: "column", gap: "6px" }}>
              {NAV_ITEMS.map((item) => {
                if ("children" in item) {
                  return (
                    <Box key={item.label}>
                      <Typography sx={sectionHeaderSx}>{item.label}</Typography>
                      <Stack spacing="6px">
                        {item.children.map((child) => {
                          const isRequests = child.href === "/app/m/catalogue/requests";
                          return (
                            <NavItem key={child.href} href={child.href} icon={child.icon}>
                              {isRequests && pendingCount > 0 ? (
                                <Badge
                                  badgeContent={pendingCount}
                                  color="error"
                                  sx={{ "& .MuiBadge-badge": { right: -14, top: 1 } }}
                                >
                                  <ListItemText
                                    primary={child.label}
                                    primaryTypographyProps={{ sx: { fontSize: "inherit", fontWeight: "inherit" } }}
                                  />
                                </Badge>
                              ) : (
                                <ListItemText
                                  primary={child.label}
                                  primaryTypographyProps={{ sx: { fontSize: "inherit", fontWeight: "inherit" } }}
                                />
                              )}
                            </NavItem>
                          );
                        })}
                      </Stack>
                    </Box>
                  );
                }

                const isNotifications = item.href === "/app/m/notifications";
                return (
                  <NavItem key={item.href} href={item.href} icon={item.icon}>
                    {isNotifications && unreadNotificationsCount > 0 ? (
                      <Badge
                        badgeContent={unreadNotificationsCount}
                        color="error"
                        aria-label={`${unreadNotificationsCount} notification${unreadNotificationsCount > 1 ? "s" : ""} non lue${unreadNotificationsCount > 1 ? "s" : ""}`}
                        sx={{ "& .MuiBadge-badge": { right: -14, top: 1 } }}
                      >
                        <ListItemText
                          primary={item.label}
                          primaryTypographyProps={{ sx: { fontSize: "inherit", fontWeight: "inherit" } }}
                        />
                      </Badge>
                    ) : (
                      <ListItemText
                        primary={item.label}
                        primaryTypographyProps={{ sx: { fontSize: "inherit", fontWeight: "inherit" } }}
                      />
                    )}
                  </NavItem>
                );
              })}

              {state.status === "authenticated" && state.user.role === "ADMIN" && (
                <Box>
                  <Typography sx={sectionHeaderSx}>Administration</Typography>
                  <Stack spacing="6px">
                    {ADMIN_ITEMS.map((item) => (
                      <NavItem key={item.href} href={item.href} icon={item.icon}>
                        <ListItemText
                          primary={item.label}
                          primaryTypographyProps={{ sx: { fontSize: "inherit", fontWeight: "inherit" } }}
                        />
                      </NavItem>
                    ))}
                  </Stack>
                </Box>
              )}
            </List>
          </Box>

          {isAuthenticated && (
            <Box sx={{ borderTop: `1px solid ${COLOR.borderSubtle}`, p: "14px" }}>
              <Box
                component="button"
                type="button"
                id="account-menu-button"
                aria-haspopup="menu"
                aria-controls={menuOpen ? "account-menu" : undefined}
                aria-expanded={menuOpen ? "true" : undefined}
                onClick={(e) => setMenuAnchor(e.currentTarget)}
                sx={{
                  display: "flex", alignItems: "center", gap: "10px", width: "100%",
                  border: "none", background: "transparent", cursor: "pointer",
                  borderRadius: "13px", p: "8px", textAlign: "left", fontFamily: "inherit",
                  "&:hover": { bgcolor: COLOR.gray75 },
                }}
              >
                <PersonAvatar
                  name={fullName}
                  photoUrl={resolveApiAssetUrl(state.status === "authenticated" ? state.user.profilePictureUrl : null)}
                  size="md"
                />
                <Box sx={{ minWidth: 0, flex: 1 }}>
                  <Typography noWrap sx={{ fontSize: "14px", fontWeight: 700, color: COLOR.gray900 }}>
                    {fullName}
                  </Typography>
                  <Typography noWrap sx={{ fontSize: "12px", color: COLOR.gray500 }}>
                    {ROLE_LABEL[state.user.role] ?? state.user.role}
                  </Typography>
                </Box>
                <ExpandMoreIcon sx={{ fontSize: 19, color: COLOR.gray400, flex: "none" }} />
              </Box>

              <Menu
                id="account-menu"
                anchorEl={menuAnchor}
                open={menuOpen}
                onClose={() => setMenuAnchor(null)}
                anchorOrigin={{ vertical: "top", horizontal: "left" }}
                transformOrigin={{ vertical: "bottom", horizontal: "left" }}
                slotProps={{
                  paper: { sx: { width: 232, borderRadius: "14px", p: "8px" } },
                  list: { "aria-labelledby": "account-menu-button" },
                }}
              >
                <MenuItem
                  onClick={() => {
                    setMenuAnchor(null);
                    navigate("/app/m/profile");
                  }}
                  sx={{
                    height: 42, borderRadius: "10px", color: COLOR.gray600, fontWeight: 600, fontSize: "14px",
                    "&:hover": { bgcolor: COLOR.gray75 },
                  }}
                >
                  <PersonOutlineOutlinedIcon sx={{ fontSize: 18, mr: "10px" }} />
                  Mon profil
                </MenuItem>
                {pushStatus === "permission-default" && (
                  <MenuItem
                    onClick={() => {
                      setMenuAnchor(null);
                      void subscribeToPush();
                    }}
                    sx={{
                      height: 42, borderRadius: "10px", color: COLOR.gray600, fontWeight: 600, fontSize: "14px",
                      "&:hover": { bgcolor: COLOR.gray75 },
                    }}
                  >
                    <NotificationsActiveOutlinedIcon sx={{ fontSize: 18, mr: "10px" }} />
                    Activer les notifications
                  </MenuItem>
                )}
                {pwaInstall.variant !== "unavailable" && (
                  <MenuItem
                    onClick={() => {
                      if (!pwaInstall.onAction) return;
                      setMenuAnchor(null);
                      pwaInstall.onAction();
                    }}
                    disabled={pwaInstall.disabled}
                    sx={{
                      height: 42, borderRadius: "10px", color: COLOR.gray600, fontWeight: 600, fontSize: "14px",
                      "&:hover": { bgcolor: COLOR.gray75 },
                    }}
                  >
                    <GetAppOutlinedIcon sx={{ fontSize: 18, mr: "10px" }} />
                    {pwaInstall.label}
                  </MenuItem>
                )}
                <MenuItem
                  onClick={() => {
                    setMenuAnchor(null);
                    logout();
                    navigate("/login", { replace: true });
                  }}
                  sx={{
                    height: 42, borderRadius: "10px", color: COLOR.red600, fontWeight: 600, fontSize: "14px",
                    "&:hover": { bgcolor: COLOR.red50 },
                  }}
                >
                  <LogoutIcon sx={{ fontSize: 18, mr: "10px" }} />
                  Se déconnecter
                </MenuItem>
              </Menu>
            </Box>
          )}
        </Stack>
      </Drawer>

      <Box component="main" sx={{ flex: 1, p: 3, minWidth: 0 }}>
        <Outlet />
      </Box>
    </Box>
  );
}
