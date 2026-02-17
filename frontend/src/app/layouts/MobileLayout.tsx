import * as React from "react";
import { Outlet, useLocation, useNavigate } from "react-router-dom";
import {
  Badge,
  BottomNavigation,
  BottomNavigationAction,
  Paper,
  Box,
} from "@mui/material";
import LocalOfferIcon from "@mui/icons-material/LocalOffer";
import AssignmentIcon from "@mui/icons-material/Assignment";
import { useQuery } from "@tanstack/react-query";

import { fetchInstrumentistOffersWithFallback } from "../features/missions/api/missions.api";
import { useAuth } from "../auth/AuthContext";

type Tab = {
  label: string;
  path: string;
  match: (pathname: string) => boolean;
};

const NAV_HEIGHT = 56;

// Safe-area iOS (ne casse rien ailleurs)
const SAFE_AREA_STYLE: React.CSSProperties = {
  paddingBottom: "env(safe-area-inset-bottom)",
};

// D) Limite/purge
const SEEN_IDS_MAX = 100;

const LS_SEEN_OFFERS_KEY = "instrumentist.offers.seenIds";
const LS_NEW_OFFERS_FLAG_KEY = "instrumentist.offers.hasNew"; // best-effort local (per device)

function readSeenOfferIds(): number[] {
  try {
    const raw = localStorage.getItem(LS_SEEN_OFFERS_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed
      .map((x) => Number(x))
      .filter((n) => Number.isFinite(n) && n > 0);
  } catch {
    return [];
  }
}

function writeSeenOfferIds(ids: number[]) {
  try {
    // D) purge: garder les N derniers
    const limited = ids.slice(-SEEN_IDS_MAX);
    localStorage.setItem(LS_SEEN_OFFERS_KEY, JSON.stringify(limited));
  } catch {
    // ignore (private mode / storage disabled)
  }
}

function readHasNewFlag(): boolean {
  try {
    return localStorage.getItem(LS_NEW_OFFERS_FLAG_KEY) === "1";
  } catch {
    return false;
  }
}

function writeHasNewFlag(v: boolean) {
  try {
    localStorage.setItem(LS_NEW_OFFERS_FLAG_KEY, v ? "1" : "0");
  } catch {
    // ignore
  }
}

export function MobileLayout() {
  const navigate = useNavigate();
  const location = useLocation();
  const pathname = location.pathname;

  const { state, logout } = useAuth();
  const isAuthenticated = state.status === "authenticated";

  const isInstrumentist = pathname.startsWith("/app/i");

  // C) polling seulement sur /app/i/offers (et /app/i redirige vers offers)
  const isOnOffersPage = pathname.startsWith("/app/i/offers");

  const tabs: Tab[] = [
    {
      label: "Offres",
      path: "/app/i/offers",
      match: (p) => p === "/app/i" || p.startsWith("/app/i/offers"),
    },
    {
      label: "Mes missions",
      path: "/app/i/my-missions",
      match: (p) =>
        p.startsWith("/app/i/my-missions") || p.startsWith("/app/i/missions/"),
    },
  ];

  const activeIndex = isInstrumentist
    ? Math.max(
        0,
        tabs.findIndex((t) => t.match(pathname)),
      )
    : -1;

  // Compteur + dot: uniquement quand instrumentiste
  // C) Polling actif uniquement sur Offres (sinon pas de refetchInterval)
  const { data: offersData } = useQuery({
    queryKey: ["missions", "offers"],
    queryFn: () => fetchInstrumentistOffersWithFallback(1, 100),
    enabled: isInstrumentist, // pas côté surgeon
    refetchInterval: isInstrumentist && isOnOffersPage ? 60_000 : false,
  });

  const offers = offersData?.items ?? [];
  const offersCount = offers.length;

  // A) best-effort local state + persisted flag (pour garder la dot si on quitte offers)
  const [hasNewOffers, setHasNewOffers] = React.useState<boolean>(() =>
    readHasNewFlag(),
  );

  // Détection de nouvelles offres: best-effort local
  React.useEffect(() => {
    if (!isInstrumentist) return;
    if (!offersData) return;

    const currentIds = offers
      .map((m) => Number(m.id))
      .filter((n) => Number.isFinite(n) && n > 0);

    const seenIds = readSeenOfferIds();
    const seen = new Set(seenIds);

    // Premier init: si vide, on initialise sans marquer "new"
    if (seenIds.length === 0) {
      writeSeenOfferIds(currentIds);
      setHasNewOffers(false);
      writeHasNewFlag(false);
      return;
    }

    const anyNew = currentIds.some((id) => !seen.has(id));

    // Si de nouvelles offres apparaissent:
    // - si on est sur Offres => on marque vu immédiatement (pas de dot)
    // - sinon => on active la dot
    if (anyNew) {
      if (isOnOffersPage) {
        writeSeenOfferIds(currentIds);
        setHasNewOffers(false);
        writeHasNewFlag(false);
      } else {
        setHasNewOffers(true);
        writeHasNewFlag(true);
      }
    }
  }, [isInstrumentist, offersData, offers, isOnOffersPage]);

  // Quand l'utilisateur revient sur Offres : mark as seen + reset dot
  React.useEffect(() => {
    if (!isInstrumentist) return;
    if (!isOnOffersPage) return;

    const currentIds = offers
      .map((m) => Number(m.id))
      .filter((n) => Number.isFinite(n) && n > 0);

    if (currentIds.length > 0) {
      writeSeenOfferIds(currentIds);
    }
    setHasNewOffers(false);
    writeHasNewFlag(false);
  }, [isInstrumentist, isOnOffersPage, offers]);

  return (
    <Box
      sx={{
        minHeight: "100vh",
        boxSizing: "border-box",
        px: 1.5,
        pt: 1.5,
        pb: isInstrumentist
          ? `calc(${NAV_HEIGHT}px + env(safe-area-inset-bottom) + 16px)`
          : 1.5,
      }}
    >
      {/* Header minimal avec logout (sans impacter la logique métier) */}
      {isAuthenticated && (
        <Box
          sx={{
            display: "flex",
            justifyContent: "flex-end",
            mb: 1,
          }}
        >
          <button
            onClick={() => {
              logout();
              navigate("/login", { replace: true });
            }}
          >
            Logout
          </button>
        </Box>
      )}

      <Outlet />

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
            ...SAFE_AREA_STYLE,
          }}
        >
          <BottomNavigation
            showLabels
            value={activeIndex}
            onChange={(_, newValue: number) => {
              const tab = tabs[newValue];
              if (tab) navigate(tab.path);
            }}
            sx={{
              height: NAV_HEIGHT,
              "& .MuiBottomNavigationAction-root": { minWidth: 0 },
              "& .MuiBottomNavigationAction-root.Mui-selected": {
                color: "primary.main", // 🎨 actif = theme primary
              },
            }}
          >
            <BottomNavigationAction
              label="Offres"
              icon={
                <Badge
                  badgeContent={offersCount}
                  color="error"
                  overlap="circular"
                >
                  <Badge
                    variant="dot"
                    color="error"
                    overlap="circular"
                    invisible={!hasNewOffers}
                  >
                    <LocalOfferIcon />
                  </Badge>
                </Badge>
              }
              sx={{ "&.Mui-selected": { color: "primary.main" } }}
            />
            <BottomNavigationAction
              label="Mes missions"
              icon={<AssignmentIcon />}
              sx={{ "&.Mui-selected": { color: "primary.main" } }}
            />
          </BottomNavigation>
        </Paper>
      )}
    </Box>
  );
}
