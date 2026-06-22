/**
 * Design tokens for the Planning V2 module, lifted from the hi-fi handoff spec
 * (`SurgicalHub V2 Design`). Deliberately distinct from the app's main palette —
 * this is a desktop-first module; the green brand color is reserved for
 * "OK / success" status only, never for primary actions here.
 */
export const planningV2Colors = {
  brand: "#1B5FD0",
  brandHover: "#16559e",
  ok: "#42A882",
  warnFg: "#B7791F",
  warnBg: "#FEF6E7",
  warnDot: "#F0A91B",
  critFg: "#C62F36",
  critBg: "#FDEEEE",
  critDot: "#E5484D",
  infoFg: "#2D7FF9",
  infoBg: "#EDF4FF",
  page: "#F5F7FA",
  cardBorder: "#E7EBEF",
  divider: "#EFF2F5",
  textTitle: "#16202B",
  textBody: "#566270",
  textSecondary: "#98A2AE",
  textMuted: "#727E8C",
  textStrong: "#3A4754",
  selectedBg: "#EDF4FF",
} as const;

/** Muted alert severity palette (§5 — "assistance, not alarm"). */
export const alertSeverityTokens = {
  crit: { fg: "#A8554F", bg: "#FBF2F1", dot: "#D58A84" },
  warn: { fg: "#8A6420", bg: "#FAF5E9", dot: "#DBAB4E" },
  info: { fg: "#3B6296", bg: "#EFF4FB", dot: "#7AA0D4" },
  ok: { fg: "#2C7D5F", bg: "#EFFAF5", dot: "#5BBE96" },
} as const;

export const planningV2Radii = {
  card: "12px",
  cardLg: "14px",
  modal: "18px",
  button: "10px",
  pill: "999px",
} as const;

export const planningV2Shadows = {
  card: "0 1px 2px rgba(22,32,43,.05)",
  cardHover: "0 6px 18px rgba(22,32,43,.10)",
  button: "0 2px 8px rgba(27,95,208,.28)",
  dropdown: "0 10px 28px rgba(11,19,32,.14)",
  modal: "0 24px 70px rgba(11,19,32,.35)",
  sheet: "-12px 0 48px rgba(11,19,32,.22)",
} as const;

/** Days nearing their post end-date are flagged within this window (§6). */
export const POST_ENDING_SOON_DAYS = 14;
