import * as React from "react";
import { Outlet, useLocation, useNavigate } from "react-router-dom";
import { Box, Popover, useMediaQuery } from "@mui/material";

import { useQuery } from "@tanstack/react-query";
import { usePushNotifications } from "../features/push/usePushNotifications";
import { useNotifications } from "../features/push/useNotifications";
import { useAuth } from "../auth/AuthContext";
import { fetchMissions, fetchInstrumentistOffersWithFallback } from "../features/missions/api/missions.api";
import { useInstrumentistMissionSync } from "../features/missions/sync/useInstrumentistMissionSync";
import { useToast } from "../ui/toast/useToast";
import dayjs from "dayjs";
import "dayjs/locale/fr";

dayjs.locale("fr");

// ── Design tokens (design_handoff_surgeryhub_instrumentiste) ────────────────
const GREEN_50  = "#EFFAF5";
const GREEN_100 = "#DDF4EA";
const GREEN_300 = "#8FDABF";
const GREEN_500 = "#42A882";
const GREEN_600 = "#338F6E";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_75   = "#F1F4F7";
const GRAY_400  = "#98A2AE";
const GRAY_500  = "#727E8C";
const GRAY_600  = "#566270";
const GRAY_900  = "#16202B";
const BORDER_SUBTLE = "#E7EBEF";
const RED_600 = "#E5484D";

const EASE_OUT = "cubic-bezier(0.22, 1, 0.36, 1)";
const NAV_H = 58;

type TabKey = "today" | "planning" | "offers";

type Tab = {
  key: TabKey;
  label: string;
  path: string;
  match: (pathname: string) => boolean;
  icon: React.ReactNode;
};

function HomeIcon() {
  return (
    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="m3 11 9-8 9 8" /><path d="M5 9.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5" />
    </svg>
  );
}
function CalendarIcon() {
  return (
    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <rect x="3" y="4" width="18" height="17" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" />
    </svg>
  );
}
function TagIcon() {
  return (
    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2c0 .5.2 1 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4Z" />
      <circle cx="7.5" cy="7.5" r=".8" fill="currentColor" />
    </svg>
  );
}
function BellIcon() {
  return (
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" /><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
    </svg>
  );
}
function MessagesIcon() {
  return (
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z" />
    </svg>
  );
}
function ChevronDownIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={GRAY_400} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
      <path d="m6 9 6 6 6-6" />
    </svg>
  );
}
function UserIcon() {
  return (
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="8" r="5" /><path d="M20 21a8 8 0 0 0-16 0" />
    </svg>
  );
}
function LogoutIcon() {
  return (
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" />
    </svg>
  );
}

const tabs: Tab[] = [
  { key: "today", label: "Aujourd'hui", path: "/app/i/today", match: (p) => p === "/app/i" || p === "/app/i/today", icon: <HomeIcon /> },
  { key: "planning", label: "Planning", path: "/app/i/planning", match: (p) => p.startsWith("/app/i/planning"), icon: <CalendarIcon /> },
  { key: "offers", label: "Offres", path: "/app/i/offers", match: (p) => p.startsWith("/app/i/offers"), icon: <TagIcon /> },
];

// The encoding screen has its own dark green header (EncodeHeader, rendered by
// MissionEncodingPage) — it replaces the main brand band rather than stacking under it.
const ENCODING_ROUTE_RE = /^\/app\/i\/missions\/[^/]+\/encoding$/;

function initialsOf(firstname?: string | null, lastname?: string | null): string {
  const a = (firstname ?? "").trim()[0] ?? "";
  const b = (lastname ?? "").trim()[0] ?? "";
  return (a + b).toUpperCase() || "?";
}

function todayRange() {
  const now = new Date();
  const from = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
  const to = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59);
  return { from: from.toISOString(), to: to.toISOString() };
}

// ── Decorative wave overlay for the brand band ──────────────────────────────
// Per-tab path sets — identical structure per layer (M...C...S...) across tabs so the
// browser can interpolate the CSS `d` property directly (see prototype
// `docs/design/prototypes/SurgeryHub App v2.dc.html`, ~line 1296 `waveShapes`).
const WAVE_SHAPES: Record<TabKey, { w1: string; w2: string; w3: string }> = {
  today: {
    w1: "M0 112 C 80 70, 170 158, 268 112 S 382 66, 400 92 L400 190 L0 190 Z",
    w2: "M0 150 C 110 104, 230 184, 320 144 S 380 128, 400 124 L400 190 L0 190 Z",
    w3: "M0 96 C 90 60, 190 140, 280 98 S 370 60, 400 80",
  },
  planning: {
    w1: "M0 84 C 100 128, 190 52, 290 104 S 380 140, 400 110 L400 190 L0 190 Z",
    w2: "M0 128 C 90 168, 220 96, 310 150 S 380 150, 400 140 L400 190 L0 190 Z",
    w3: "M0 140 C 80 108, 200 76, 300 128 S 370 118, 400 100",
  },
  offers: {
    w1: "M0 132 C 70 92, 200 96, 260 140 S 370 118, 400 66 L400 190 L0 190 Z",
    w2: "M0 168 C 120 128, 210 168, 330 118 S 385 108, 400 104 L400 190 L0 190 Z",
    w3: "M0 70 C 100 118, 180 58, 290 108 S 375 92, 400 116",
  },
};

// Fixed shape set for the brief "kick" pulse (arrival, leaving encoding) — same
// values as the prototype's `waveKickShapes`, independent of the active tab.
const WAVE_KICK_SHAPES = {
  w1: "M0 70 C 80 130, 170 60, 268 140 S 382 130, 400 60 L400 190 L0 190 Z",
  w2: "M0 100 C 110 170, 230 100, 320 170 S 380 100, 400 160 L400 190 L0 190 Z",
  w3: "M0 130 C 90 40, 190 150, 280 60 S 370 130, 400 50",
};

function BandWaves({ activeKey, kick }: { activeKey: TabKey | null; kick: boolean }) {
  const shapes = kick ? WAVE_KICK_SHAPES : (WAVE_SHAPES[activeKey ?? "today"] ?? WAVE_SHAPES.today);
  return (
    <svg
      style={{ position: "absolute", inset: 0, width: "100%", height: "100%", pointerEvents: "none" }}
      viewBox="0 0 400 190"
      preserveAspectRatio="none"
      fill="none"
    >
      <path
        style={{ transition: "d 1.1s cubic-bezier(0.16, 1, 0.3, 1)", d: `path('${shapes.w1}')` } as React.CSSProperties}
        fill="rgba(255,255,255,.07)"
      />
      <path
        style={{ transition: "d 1.35s cubic-bezier(0.16, 1, 0.3, 1)", d: `path('${shapes.w2}')` } as React.CSSProperties}
        fill="rgba(11,19,32,.14)"
      />
      <path
        style={{ transition: "d 1.6s cubic-bezier(0.16, 1, 0.3, 1)", d: `path('${shapes.w3}')` } as React.CSSProperties}
        stroke="rgba(255,255,255,.22)"
        strokeWidth={1.5}
        strokeDasharray="10 9"
        fill="none"
      />
    </svg>
  );
}

// ── Brand header band (shared mobile/desktop) ───────────────────────────────
function BrandBand({
  isDesktop,
  activeKey,
  waveKick,
  title,
  subtitle,
  initials,
  hasUnread,
  onBell,
  onAvatar,
}: {
  isDesktop: boolean;
  activeKey: TabKey | null;
  waveKick: boolean;
  title: string;
  subtitle: string;
  initials: string;
  hasUnread: boolean;
  onBell: () => void;
  onAvatar: (e: React.MouseEvent<HTMLElement>) => void;
}) {
  return (
    <Box sx={isDesktop ? { maxWidth: 760, mx: "auto", width: "100%", px: "20px", pt: "22px" } : { width: "100%" }}>
      <Box
        sx={{
          position: "relative",
          overflow: "hidden",
          background: `linear-gradient(140deg, ${GREEN_800} 0%, ${GREEN_600} 62%, ${GREEN_500} 118%)`,
          borderRadius: isDesktop ? "24px" : "0 0 28px 28px",
          padding: isDesktop ? "22px 26px 58px" : "12px 20px 56px",
        }}
      >
        <BandWaves activeKey={activeKey} kick={waveKick} />
        <Box sx={{ display: "flex", alignItems: "center", gap: "12px", position: "relative" }}>
          {!isDesktop && (
            <>
              <Box
                component="img"
                src="/logo-mark-transparent.png"
                alt="SurgeryHub"
                sx={{ width: 36, height: 36, objectFit: "contain", flexShrink: 0, filter: "drop-shadow(0 2px 5px rgba(0,0,0,.3))" }}
              />
              <Box sx={{ fontSize: 16, fontWeight: 800, letterSpacing: "-0.02em", color: "#fff" }}>
                Surgery<Box component="span" sx={{ color: GREEN_100 }}>Hub</Box>
              </Box>
            </>
          )}
          <Box sx={{ flex: 1 }} />
          <Box
            component="button"
            type="button"
            aria-label="Notifications"
            onClick={onBell}
            sx={{
              position: "relative",
              width: 40,
              height: 40,
              border: "none",
              background: "rgba(255,255,255,.12)",
              borderRadius: "12px",
              cursor: "pointer",
              color: "#fff",
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              transition: "background 150ms",
              "&:hover": { background: "rgba(255,255,255,.2)" },
            }}
          >
            <BellIcon />
            {hasUnread && (
              <Box
                sx={{
                  position: "absolute",
                  top: 8,
                  right: 9,
                  width: 8,
                  height: 8,
                  borderRadius: "999px",
                  background: RED_600,
                  border: `2px solid ${GREEN_700}`,
                }}
              />
            )}
          </Box>
          <Box
            component="button"
            type="button"
            aria-label="Compte"
            onClick={onAvatar}
            sx={{
              width: 38,
              height: 38,
              border: "none",
              borderRadius: "999px",
              background: "rgba(255,255,255,.92)",
              color: GREEN_800,
              fontFamily: "inherit",
              fontSize: 13,
              fontWeight: 800,
              cursor: "pointer",
            }}
          >
            {initials}
          </Box>
        </Box>
        <Box sx={{ mt: "18px", position: "relative" }}>
          <Box component="h1" sx={{ m: 0, fontSize: 26, fontWeight: 800, letterSpacing: "-0.02em", color: "#fff" }}>
            {title}
          </Box>
          <Box component="p" sx={{ m: "6px 0 0", fontSize: 14.5, color: GREEN_300, fontVariantNumeric: "tabular-nums" }}>
            {subtitle}
          </Box>
        </Box>
      </Box>
    </Box>
  );
}

// ── Desktop sidebar ──────────────────────────────────────────────────────────
function DesktopSidebar({
  activeKey,
  onNavigate,
  offersCount,
  firstname,
  lastname,
  onAvatarClick,
  onMessages,
  onNotifications,
  onProfile,
}: {
  activeKey: TabKey | null;
  onNavigate: (path: string) => void;
  offersCount: number;
  firstname?: string | null;
  lastname?: string | null;
  onAvatarClick: (e: React.MouseEvent<HTMLElement>) => void;
  onMessages: () => void;
  onNotifications: () => void;
  onProfile: () => void;
}) {
  const railItem = (active: boolean): React.CSSProperties => ({
    display: "flex",
    alignItems: "center",
    gap: 12,
    height: 48,
    padding: "0 14px",
    borderRadius: 13,
    border: "none",
    cursor: "pointer",
    fontFamily: "inherit",
    fontSize: 14,
    width: "100%",
    textAlign: "left",
    transition: "all 150ms",
    background: active ? GREEN_50 : "transparent",
    color: active ? GREEN_800 : GRAY_600,
    fontWeight: active ? 800 : 600,
  });

  // "Idle" rail items (Messages/Notifications/Profil) — never highlighted as an
  // active tab, only a hover background, per handoff (ri.idle style).
  const idleItem: React.CSSProperties = {
    display: "flex",
    alignItems: "center",
    gap: 12,
    height: 48,
    padding: "0 14px",
    borderRadius: 13,
    border: "none",
    cursor: "pointer",
    fontFamily: "inherit",
    fontSize: 14,
    width: "100%",
    textAlign: "left",
    transition: "all 150ms",
    background: "transparent",
    color: GRAY_600,
    fontWeight: 600,
  };

  const name = `${firstname ?? ""} ${lastname ?? ""}`.trim() || "Instrumentiste";

  return (
    <Box
      component="aside"
      sx={{
        width: 248,
        flexShrink: 0,
        background: "#fff",
        borderRight: `1px solid ${BORDER_SUBTLE}`,
        display: "flex",
        flexDirection: "column",
        px: "14px",
        pt: "24px",
        pb: "14px",
        position: "sticky",
        top: 0,
        height: "100vh",
      }}
    >
      <Box sx={{ display: "flex", alignItems: "center", gap: "11px", px: "10px" }}>
        <Box component="img" src="/logo-mark-transparent.png" alt="SurgeryHub" sx={{ width: 42, height: 42, objectFit: "contain", flexShrink: 0 }} />
        <Box sx={{ fontSize: 18, fontWeight: 800, letterSpacing: "-0.02em", color: GRAY_900 }}>
          Surgery<Box component="span" sx={{ color: GREEN_600 }}>Hub</Box>
        </Box>
      </Box>

      <Box component="nav" sx={{ display: "flex", flexDirection: "column", gap: "6px", mt: "34px" }}>
        {tabs.map((t) => (
          <Box key={t.key} component="button" type="button" onClick={() => onNavigate(t.path)} sx={railItem(activeKey === t.key)}>
            {t.icon}
            <span>{t.label}</span>
            {t.key === "offers" && offersCount > 0 && (
              <Box
                sx={{
                  ml: "auto",
                  minWidth: 21,
                  height: 21,
                  px: "6px",
                  borderRadius: "999px",
                  fontSize: 11.5,
                  fontWeight: 800,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  background: RED_600,
                  color: "#fff",
                }}
              >
                {offersCount > 9 ? "9+" : offersCount}
              </Box>
            )}
          </Box>
        ))}
        <Box component="button" type="button" onClick={onMessages} sx={idleItem}>
          <MessagesIcon />
          <span>Messages</span>
        </Box>
        <Box component="button" type="button" onClick={onNotifications} sx={idleItem}>
          <BellIcon />
          <span>Notifications</span>
        </Box>
        <Box component="button" type="button" onClick={onProfile} sx={idleItem}>
          <UserIcon />
          <span>Profil</span>
        </Box>
      </Box>

      <Box sx={{ flex: 1 }} />
      <Box sx={{ borderTop: `1px solid ${BORDER_SUBTLE}`, mx: "2px" }} />
      <Box
        component="button"
        type="button"
        onClick={onAvatarClick}
        sx={{
          display: "flex",
          alignItems: "center",
          gap: "11px",
          padding: "12px 8px",
          border: "none",
          background: "transparent",
          borderRadius: "12px",
          cursor: "pointer",
          fontFamily: "inherit",
          textAlign: "left",
          width: "100%",
          transition: "background 150ms",
          "&:hover": { background: GRAY_75 },
        }}
      >
        <Box sx={{ width: 38, height: 38, borderRadius: "999px", background: GREEN_100, color: GREEN_800, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 13, fontWeight: 800, flexShrink: 0 }}>
          {initialsOf(firstname, lastname)}
        </Box>
        <Box sx={{ flex: 1, minWidth: 0, display: "flex", flexDirection: "column" }}>
          <Box sx={{ fontSize: 14, fontWeight: 700, color: GRAY_900, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
            {name}
          </Box>
          <Box sx={{ fontSize: 12, color: GRAY_500 }}>Instrumentiste</Box>
        </Box>
        <ChevronDownIcon />
      </Box>
    </Box>
  );
}

// ── Mobile bottom nav (anchored variant) ────────────────────────────────────
function MobileBottomNav({
  activeKey,
  onNavigate,
  offersCount,
}: {
  activeKey: TabKey | null;
  onNavigate: (path: string) => void;
  offersCount: number;
}) {
  return (
    <Box
      component="nav"
      aria-label="Navigation instrumentiste"
      sx={{
        position: "fixed",
        left: 0,
        right: 0,
        bottom: 0,
        zIndex: 300,
        display: "flex",
        gap: "6px",
        padding: "10px 14px calc(10px + env(safe-area-inset-bottom))",
        background: "#fff",
        borderRadius: "22px 22px 0 0",
        boxShadow: "0 -8px 28px rgba(22,32,43,.14)",
      }}
    >
      {tabs.map((t) => {
        const active = activeKey === t.key;
        return (
          <Box
            key={t.key}
            component="button"
            type="button"
            onClick={() => onNavigate(t.path)}
            sx={{
              flex: 1,
              display: "flex",
              flexDirection: "column",
              alignItems: "center",
              justifyContent: "center",
              gap: "3px",
              height: NAV_H,
              border: "none",
              cursor: "pointer",
              fontFamily: "inherit",
              fontSize: 11,
              borderRadius: "16px",
              transition: `all 200ms ${EASE_OUT}`,
              background: active ? GREEN_800 : "transparent",
              color: active ? "#fff" : GRAY_500,
              fontWeight: active ? 700 : 600,
              boxShadow: active ? "0 5px 14px rgba(20,77,56,.4)" : "none",
            }}
          >
            <Box sx={{ position: "relative", display: "flex" }}>
              {t.icon}
              {t.key === "offers" && offersCount > 0 && (
                <Box
                  sx={{
                    position: "absolute",
                    top: -6,
                    right: -11,
                    minWidth: 17,
                    height: 17,
                    px: "4px",
                    borderRadius: "999px",
                    background: RED_600,
                    color: "#fff",
                    fontSize: 10.5,
                    fontWeight: 800,
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    border: "2px solid #fff",
                  }}
                >
                  {offersCount > 9 ? "9+" : offersCount}
                </Box>
              )}
            </Box>
            <span>{t.label}</span>
          </Box>
        );
      })}
    </Box>
  );
}

// ── Account menu (shPop) ─────────────────────────────────────────────────────
function AccountMenu({
  anchorEl,
  onClose,
  firstname,
  lastname,
  onProfile,
  onLogout,
}: {
  anchorEl: HTMLElement | null;
  onClose: () => void;
  firstname?: string | null;
  lastname?: string | null;
  onProfile: () => void;
  onLogout: () => void;
}) {
  const name = `${firstname ?? ""} ${lastname ?? ""}`.trim() || "Instrumentiste";
  const itemSx = {
    display: "flex",
    alignItems: "center",
    gap: "10px",
    width: "100%",
    height: 42,
    padding: "0 12px",
    border: "none",
    background: "transparent",
    borderRadius: "10px",
    cursor: "pointer",
    fontFamily: "inherit",
    fontSize: 14,
    fontWeight: 600,
    textAlign: "left" as const,
  };

  return (
    <Popover
      open={Boolean(anchorEl)}
      anchorEl={anchorEl}
      onClose={onClose}
      anchorOrigin={{ vertical: "bottom", horizontal: "right" }}
      transformOrigin={{ vertical: "top", horizontal: "right" }}
      slotProps={{
        paper: {
          sx: {
            width: 232,
            borderRadius: "14px",
            border: `1px solid ${BORDER_SUBTLE}`,
            boxShadow: "0 6px 16px rgba(22,32,43,.08), 0 16px 40px rgba(22,32,43,.12)",
            p: "8px",
            animation: `shPop 180ms ${EASE_OUT}`,
            "@keyframes shPop": {
              from: { opacity: 0, transform: "translateY(10px) scale(.98)" },
              to: { opacity: 1, transform: "none" },
            },
          },
        },
      }}
    >
      <Box sx={{ px: "12px", pt: "10px", pb: "8px" }}>
        <Box sx={{ fontSize: 14, fontWeight: 700 }}>{name}</Box>
        <Box sx={{ fontSize: 12.5, color: GRAY_500 }}>Instrumentiste</Box>
      </Box>
      <Box sx={{ borderTop: "1px dashed #DDE2E8", mx: "6px", my: "4px" }} />
      <Box component="button" type="button" onClick={onProfile} sx={{ ...itemSx, color: GRAY_600, "&:hover": { background: GRAY_75 } }}>
        <UserIcon />
        Mon profil
      </Box>
      <Box component="button" type="button" onClick={onLogout} sx={{ ...itemSx, color: RED_600, "&:hover": { background: "#FDEEEE" } }}>
        <LogoutIcon />
        Se déconnecter
      </Box>
    </Popover>
  );
}

// ── Main layout ──────────────────────────────────────────────────────────────
export function MobileLayout() {
  const navigate = useNavigate();
  const location = useLocation();
  const pathname = location.pathname;
  const { state, logout } = useAuth();
  const toast = useToast();

  const [menuAnchor, setMenuAnchor] = React.useState<HTMLElement | null>(null);
  const isDesktop = useMediaQuery("(min-width:900px)");

  useInstrumentistMissionSync();

  const isInstrumentist = pathname.startsWith("/app/i");
  const activeTab = tabs.find((t) => t.match(pathname))?.key ?? null;

  // "Kick" pulse on the header waves — same mechanism as the prototype's kickWaves():
  // a very brief (70ms) flip to a fixed distorted shape, reverted before the CSS
  // transition (1.1-1.6s) can complete, producing an impulse rather than a full morph.
  const [waveKick, setWaveKick] = React.useState(false);
  const kickTimeoutRef = React.useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const kickWaves = React.useCallback(() => {
    clearTimeout(kickTimeoutRef.current);
    setWaveKick(true);
    kickTimeoutRef.current = setTimeout(() => setWaveKick(false), 70);
  }, []);
  React.useEffect(() => () => clearTimeout(kickTimeoutRef.current), []);

  // Arrival kick — equivalent of the prototype's post-login kick: fires once when
  // this layout mounts (fresh login, or a hard reload while already in the app).
  React.useEffect(() => {
    kickWaves();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Leaving-encoding kick — covers both the prototype's closeEncode and validateTap
  // (both simply leave the encoding screen in the real router).
  const previousPathnameRef = React.useRef(pathname);
  React.useEffect(() => {
    const wasEncoding = ENCODING_ROUTE_RE.test(previousPathnameRef.current);
    const isEncoding = ENCODING_ROUTE_RE.test(pathname);
    if (wasEncoding && !isEncoding) kickWaves();
    previousPathnameRef.current = pathname;
  }, [pathname, kickWaves]);

  const isEncodingRoute = ENCODING_ROUTE_RE.test(pathname);

  const { from, to } = React.useMemo(() => todayRange(), []);
  const { data: todayData } = useQuery({
    queryKey: ["missions", "today", { from, to }],
    queryFn: () => fetchMissions(1, 10, { assignedToMe: true, status: "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED", from, to }),
    enabled: isInstrumentist,
    refetchInterval: isInstrumentist ? 60_000 : false,
  });
  const todayCount = todayData?.items?.length ?? 0;

  const { data: offersData } = useQuery({
    queryKey: ["missions", "offers"],
    queryFn: () => fetchInstrumentistOffersWithFallback(1, 100),
    enabled: isInstrumentist,
    refetchInterval: isInstrumentist ? 60_000 : false,
  });
  const offersCount = offersData?.items?.length ?? 0;

  const { pushState, requestPermission } = usePushNotifications();
  const { badgeLabel } = useNotifications();

  const firstname = state.status === "authenticated" ? state.user.firstname : null;
  const lastname = state.status === "authenticated" ? state.user.lastname : null;

  const handleLogout = () => {
    setMenuAnchor(null);
    logout();
    navigate("/login", { replace: true });
  };

  const { title, subtitle } = React.useMemo(() => {
    const dateLabel = dayjs().format("dddd D MMMM").replace(/^\w/, (c) => c.toUpperCase());
    if (activeTab === "planning") {
      return { title: "Planning", subtitle: dayjs().format("MMMM YYYY").replace(/^\w/, (c) => c.toUpperCase()) };
    }
    if (activeTab === "offers") {
      const sub = offersCount === 0
        ? "Aucune offre en attente"
        : `${offersCount} ${offersCount > 1 ? "offres correspondent" : "offre correspond"} à vos disponibilités`;
      return { title: "Offres", subtitle: sub };
    }
    const name = firstname ? `, ${firstname}` : "";
    return {
      title: `\u{1F44B} Bonjour${name} !`,
      subtitle: `${dateLabel} · ${todayCount} mission${todayCount > 1 ? "s" : ""} aujourd'hui`,
    };
  }, [activeTab, firstname, offersCount, todayCount]);

  if (!isInstrumentist) {
    return (
      <Box sx={{ minHeight: "100vh", bgcolor: "background.default" }}>
        <Outlet />
      </Box>
    );
  }

  const content = (
    <Box
      sx={{
        maxWidth: isEncodingRoute ? 760 : 720,
        mx: "auto",
        width: "100%",
        px: isEncodingRoute ? 0 : "20px",
        pt: 0,
        pb: isDesktop ? "40px" : "130px",
        mt: isEncodingRoute ? 0 : "-34px",
        position: "relative",
      }}
    >
      {pushState === "prompt" && (
        <Box
          sx={{
            display: "flex",
            alignItems: "center",
            gap: 1,
            mb: "14px",
            px: "16px",
            py: "10px",
            borderRadius: "14px",
            background: "#fff",
            boxShadow: "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)",
          }}
        >
          <Box sx={{ flex: 1, fontSize: 13, color: GRAY_600 }}>
            Activez les notifications pour les nouvelles missions
          </Box>
          <Box
            component="button"
            type="button"
            onClick={requestPermission}
            sx={{ border: "none", background: "transparent", color: GREEN_700, fontWeight: 700, fontSize: 13, cursor: "pointer", textDecoration: "underline", p: 0 }}
          >
            Activer
          </Box>
        </Box>
      )}
      <Box
        key={pathname}
        sx={{
          animation: "shFade 250ms cubic-bezier(.22,1,.36,1)",
          "@keyframes shFade": {
            from: { opacity: 0, transform: "translateY(6px)" },
            to: { opacity: 1, transform: "none" },
          },
        }}
      >
        <Outlet />
      </Box>
    </Box>
  );

  return (
    <Box sx={{ minHeight: "100vh", display: "flex", background: "#F5F7FA" }}>
      {isDesktop && (
        <DesktopSidebar
          activeKey={activeTab}
          onNavigate={(path) => navigate(path)}
          offersCount={offersCount}
          firstname={firstname}
          lastname={lastname}
          onAvatarClick={(e) => setMenuAnchor(e.currentTarget)}
          onMessages={() => toast.warning("Fonctionnalité bientôt disponible.")}
          onNotifications={() => navigate("/app/i/notifications")}
          onProfile={() => navigate("/app/i/profile")}
        />
      )}

      <Box sx={{ flex: 1, minWidth: 0, display: "flex", flexDirection: "column" }}>
        {!isEncodingRoute && (
          <BrandBand
            isDesktop={isDesktop}
            activeKey={activeTab}
            waveKick={waveKick}
            title={title}
            subtitle={subtitle}
            initials={initialsOf(firstname, lastname)}
            hasUnread={!!badgeLabel}
            onBell={() => navigate("/app/i/notifications")}
            onAvatar={(e) => setMenuAnchor(e.currentTarget)}
          />
        )}
        {content}
      </Box>

      {!isDesktop && (
        <MobileBottomNav
          activeKey={activeTab}
          onNavigate={(path) => navigate(path)}
          offersCount={offersCount}
        />
      )}

      <AccountMenu
        anchorEl={menuAnchor}
        onClose={() => setMenuAnchor(null)}
        firstname={firstname}
        lastname={lastname}
        onProfile={() => { setMenuAnchor(null); navigate("/app/i/profile"); }}
        onLogout={handleLogout}
      />
    </Box>
  );
}
