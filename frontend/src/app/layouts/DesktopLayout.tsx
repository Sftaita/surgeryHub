import { Outlet, NavLink, useNavigate } from "react-router-dom";
import {
  Badge,
  Box,
  Divider,
  Drawer,
  List,
  ListItemButton,
  ListItemText,
  Stack,
  Typography,
  Button,
} from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { useAuth } from "../auth/AuthContext";
import { getMaterialRequests } from "../features/manager-catalogue/api/catalogue.api";

const NAV_WIDTH = 220;

const NAV_ITEMS = [
  {
    label: "Missions",
    href: "/app/m/missions",
  },
  {
    label: "Instrumentistes",
    href: "/app/m/instrumentists",
  },
  {
    label: "Chirurgiens",
    href: "/app/m/surgeons",
  },
  {
    label: "Catalogue",
    children: [
      { label: "Matériel", href: "/app/m/catalogue" },
      { label: "Demandes matériel", href: "/app/m/catalogue/requests" },
    ],
  },
] as const;

export function DesktopLayout() {
  const navigate = useNavigate();
  const { state, logout } = useAuth();
  const isAuthenticated = state.status === "authenticated";

  const pendingRequestsQuery = useQuery({
    queryKey: ["material-requests", "PENDING"],
    queryFn: () => getMaterialRequests({ status: "PENDING" }),
    refetchInterval: 60_000,
  });
  const pendingCount = pendingRequestsQuery.data?.items?.length ?? 0;

  return (
    <Box sx={{ display: "flex", minHeight: "100vh" }}>
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
        <Stack
          sx={{ height: "100%", py: 2 }}
          direction="column"
          justifyContent="space-between"
        >
          <Box>
            <Box sx={{ px: 2, pb: 1.5 }}>
              <Typography variant="subtitle2" fontWeight={700} color="primary">
                SurgicalHub
              </Typography>
            </Box>

            <Divider />

            <List dense sx={{ pt: 1 }}>
              {NAV_ITEMS.map((item) => {
                if ("children" in item) {
                  return (
                    <Box key={item.label}>
                      <Typography
                        variant="caption"
                        color="text.secondary"
                        sx={{ px: 2, pt: 1, pb: 0.25, display: "block", textTransform: "uppercase", letterSpacing: 0.5 }}
                      >
                        {item.label}
                      </Typography>
                      {item.children.map((child) => {
                        const isRequests = child.href === "/app/m/catalogue/requests";
                        return (
                          <NavLink
                            key={child.href}
                            to={child.href}
                            style={{ textDecoration: "none", color: "inherit" }}
                          >
                            {({ isActive }) => (
                              <ListItemButton
                                selected={isActive}
                                sx={{ pl: 3, py: 0.75 }}
                              >
                                <ListItemText
                                  primary={
                                    isRequests && pendingCount > 0 ? (
                                      <Badge
                                        badgeContent={pendingCount}
                                        color="error"
                                        sx={{ "& .MuiBadge-badge": { right: -14, top: 1 } }}
                                      >
                                        {child.label}
                                      </Badge>
                                    ) : (
                                      child.label
                                    )
                                  }
                                  primaryTypographyProps={{ variant: "body2" }}
                                />
                              </ListItemButton>
                            )}
                          </NavLink>
                        );
                      })}
                    </Box>
                  );
                }

                return (
                  <NavLink
                    key={item.href}
                    to={item.href}
                    style={{ textDecoration: "none", color: "inherit" }}
                  >
                    {({ isActive }) => (
                      <ListItemButton selected={isActive} sx={{ py: 0.75 }}>
                        <ListItemText
                          primary={item.label}
                          primaryTypographyProps={{ variant: "body2" }}
                        />
                      </ListItemButton>
                    )}
                  </NavLink>
                );
              })}
            </List>
          </Box>

          {isAuthenticated && (
            <Box sx={{ px: 2 }}>
              <Button
                size="small"
                variant="text"
                color="inherit"
                fullWidth
                onClick={() => {
                  logout();
                  navigate("/login", { replace: true });
                }}
              >
                Déconnexion
              </Button>
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
