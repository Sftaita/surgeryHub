import * as React from "react";
import { Outlet, useLocation, useNavigate } from "react-router-dom";
import {
  Badge,
  BottomNavigation,
  BottomNavigationAction,
  Paper,
  Box,
  AppBar,
  Toolbar,
  Typography,
  IconButton,
  Menu,
  MenuItem,
  ListItemIcon,
  ListItemText,
} from "@mui/material";
import TodayIcon from "@mui/icons-material/Today";
import CalendarMonthIcon from "@mui/icons-material/CalendarMonth";
import LocalOfferIcon from "@mui/icons-material/LocalOffer";
import NotificationsIcon from "@mui/icons-material/Notifications";
import AccountCircleIcon from "@mui/icons-material/AccountCircle";
import LogoutIcon from "@mui/icons-material/Logout";
import PersonIcon from "@mui/icons-material/Person";

import { useQuery } from "@tanstack/react-query";
import { usePushNotifications } from "../features/push/usePushNotifications";
import { useNotifications } from "../features/push/useNotifications";
import { useAuth } from "../auth/AuthContext";
import { fetchInstrumentistOffersWithFallback } from "../features/missions/api/missions.api";
import { useInstrumentistMissionSync } from "../features/missions/sync/useInstrumentistMissionSync";

type Tab = {
  label: string;
  path: string;
  match: (pathname: string) => boolean;
};

const NAV_HEIGHT = 56;
const APPBAR_HEIGHT = 56;

const SAFE_AREA_BOTTOM: React.CSSProperties = {
  paddingBottom: "env(safe-area-inset-bottom)",
};

const tabs: Tab[] = [
  {
    label: "Aujourd'hui",
    path: "/app/i/today",
    match: (p) => p === "/app/i" || p === "/app/i/today",
  },
  {
    label: "Planning",
    path: "/app/i/planning",
    match: (p) => p.startsWith("/app/i/planning"),
  },
  {
    label: "Offres",
    path: "/app/i/offers",
    match: (p) => p.startsWith("/app/i/offers"),
  },
];

function getTabLabel(pathname: string): string {
  const tab = tabs.find((t) => t.match(pathname));
  if (tab) return tab.label;
  if (pathname.startsWith("/app/i/notifications")) return "Notifications";
  if (pathname.startsWith("/app/i/missions")) return "Mission";
  return "SurgicalHub";
}

export function MobileLayout() {
  const navigate = useNavigate();
  const location = useLocation();
  const pathname = location.pathname;
  const { logout } = useAuth();

  const [menuAnchor, setMenuAnchor] = React.useState<null | HTMLElement>(null);

  useInstrumentistMissionSync();

  const isInstrumentist = pathname.startsWith("/app/i");
  const isPlanning = pathname.startsWith("/app/i/planning");

  const { data: offersData } = useQuery({
    queryKey: ["missions", "offers"],
    queryFn: () => fetchInstrumentistOffersWithFallback(1, 100),
    enabled: isInstrumentist,
    refetchInterval: isInstrumentist ? 60_000 : false,
  });
  const offersCount = offersData?.items?.length ?? 0;

  const { pushState, requestPermission } = usePushNotifications();
  const { badgeLabel } = useNotifications();

  const activeIndex = isInstrumentist
    ? Math.max(-1, tabs.findIndex((t) => t.match(pathname)))
    : -1;

  const handleLogout = () => {
    setMenuAnchor(null);
    logout();
    navigate("/login", { replace: true });
  };

  return (
    <Box sx={{ minHeight: "100vh", display: "flex", flexDirection: "column", bgcolor: "background.default" }}>
      {/* Top App Bar */}
      {isInstrumentist && (
        <AppBar
          position="fixed"
          color="default"
          elevation={0}
          sx={{
            bgcolor: "background.paper",
            zIndex: (theme) => theme.zIndex.appBar,
            boxShadow: "0 1px 8px rgba(0,0,0,0.06)",
          }}
        >
          <Toolbar sx={{ minHeight: APPBAR_HEIGHT, px: 2 }}>
            <Typography variant="subtitle1" fontWeight={700} color="primary" sx={{ flex: 1 }}>
              {getTabLabel(pathname)}
            </Typography>

            <IconButton
              size="small"
              onClick={() => navigate("/app/i/notifications")}
              aria-label="Notifications"
            >
              <Badge badgeContent={badgeLabel} color="error">
                <NotificationsIcon fontSize="small" />
              </Badge>
            </IconButton>

            <IconButton
              size="small"
              onClick={(e) => setMenuAnchor(e.currentTarget)}
              aria-label="Profil"
              sx={{ ml: 0.5 }}
            >
              <AccountCircleIcon fontSize="small" />
            </IconButton>

            <Menu
              anchorEl={menuAnchor}
              open={Boolean(menuAnchor)}
              onClose={() => setMenuAnchor(null)}
              transformOrigin={{ horizontal: "right", vertical: "top" }}
              anchorOrigin={{ horizontal: "right", vertical: "bottom" }}
            >
              <MenuItem onClick={() => { setMenuAnchor(null); navigate("/app/i/profile"); }}>
                <ListItemIcon>
                  <PersonIcon fontSize="small" />
                </ListItemIcon>
                <ListItemText>Mon profil</ListItemText>
              </MenuItem>
              <MenuItem onClick={handleLogout}>
                <ListItemIcon>
                  <LogoutIcon fontSize="small" />
                </ListItemIcon>
                <ListItemText>Se déconnecter</ListItemText>
              </MenuItem>
            </Menu>
          </Toolbar>
        </AppBar>
      )}

      {/* Bandeau activation notifications */}
      {isInstrumentist && pushState === "prompt" && (
        <Box
          sx={{
            position: "fixed",
            top: APPBAR_HEIGHT,
            left: 0,
            right: 0,
            zIndex: (theme) => theme.zIndex.appBar - 1,
            bgcolor: "primary.main",
            color: "primary.contrastText",
            px: 2,
            py: 0.75,
            display: "flex",
            alignItems: "center",
            gap: 1,
          }}
        >
          <Typography variant="body2" sx={{ flex: 1, fontSize: "0.8rem" }}>
            Activez les notifications pour les nouvelles missions
          </Typography>
          <Typography
            component="button"
            variant="body2"
            fontWeight={700}
            sx={{
              border: "none",
              bgcolor: "transparent",
              color: "inherit",
              cursor: "pointer",
              fontSize: "0.8rem",
              textDecoration: "underline",
              p: 0,
            }}
            onClick={requestPermission}
          >
            Activer
          </Typography>
        </Box>
      )}

      {/* Content area */}
      <Box
        sx={{
          flex: 1,
          px: isPlanning ? 0 : 1.5,
          pt: isPlanning
            ? `${APPBAR_HEIGHT}px`
            : `${APPBAR_HEIGHT + (pushState === "prompt" ? 40 : 0) + 12}px`,
          pb: isPlanning
            ? 0
            : `calc(${NAV_HEIGHT}px + env(safe-area-inset-bottom) + 16px)`,
        }}
      >
        <Outlet />
      </Box>

      {/* Bottom nav */}
      {isInstrumentist && (
        <Paper
          elevation={8}
          component="nav"
          aria-label="Navigation instrumentiste"
          sx={{
            position: "fixed",
            left: 0,
            right: 0,
            bottom: 0,
            borderTop: "1px solid rgba(0,0,0,0.12)",
            ...SAFE_AREA_BOTTOM,
          }}
        >
          <BottomNavigation
            showLabels
            value={activeIndex >= 0 ? activeIndex : false}
            onChange={(_, newValue: number) => {
              const tab = tabs[newValue];
              if (tab) navigate(tab.path);
            }}
            sx={{
              height: NAV_HEIGHT,
              bgcolor: "background.paper",
              "& .MuiBottomNavigationAction-root": {
                color: "text.disabled",
                fontSize: "0.7rem",
              },
              "& .MuiBottomNavigationAction-root.Mui-selected": {
                color: "primary.main",
              },
            }}
          >
            <BottomNavigationAction
              label="Aujourd'hui"
              icon={<TodayIcon />}
            />
            <BottomNavigationAction
              label="Planning"
              icon={<CalendarMonthIcon />}
            />
            <BottomNavigationAction
              label="Offres"
              icon={
                <Badge badgeContent={offersCount || undefined} color="error" max={9}>
                  <LocalOfferIcon />
                </Badge>
              }
            />
          </BottomNavigation>
        </Paper>
      )}
    </Box>
  );
}
