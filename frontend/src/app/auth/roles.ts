export type Role = "INSTRUMENTIST" | "SURGEON" | "MANAGER" | "ADMIN";

/**
 * Rôles mobile-first
 */
export function isMobileRole(
  role: string
): role is "INSTRUMENTIST" | "SURGEON" {
  return role === "INSTRUMENTIST" || role === "SURGEON";
}

/**
 * Rôles desktop-first
 */
export function isDesktopRole(role: string): role is "MANAGER" | "ADMIN" {
  return role === "MANAGER" || role === "ADMIN";
}

/**
 * L'écran "chez soi" pour un rôle donné — unique source de vérité, utilisée à la fois
 * par la redirection post-login et par le fallback de chaque garde de route
 * (RequireInstrumentist/RequireSurgeon/RequireManager). Bug corrigé (Lot 3, découvert
 * via AppRouter.test.tsx) : un fallback figé par garde (ex. RequireInstrumentist
 * renvoyant tout non-INSTRUMENTIST vers /app/m/dashboard) provoquait une boucle de
 * redirection infinie pour un SURGEON visitant une route /app/i/* — RequireManager le
 * renvoyait ensuite vers /app/i/today, RequireInstrumentist l'en renvoyait à nouveau
 * vers /app/m/dashboard, indéfiniment. Chaque garde doit rediriger directement vers le
 * vrai "chez soi" du rôle réel, jamais vers un chemin fixe qui suppose une seule autre
 * alternative possible.
 */
export function homePathForRole(role: string): string {
  if (isDesktopRole(role)) return "/app/m/dashboard";
  if (role === "SURGEON") return "/app/s";
  if (isMobileRole(role)) return "/app/i/today";
  return "/app/forbidden";
}
