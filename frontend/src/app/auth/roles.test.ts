import { describe, it, expect } from "vitest";
import { homePathForRole, isDesktopRole, isMobileRole } from "./roles";

/**
 * Régression (Lot 3, D-097, découvert via AppRouter.test.tsx) — `homePathForRole()` est
 * l'unique source de vérité pour "où renvoyer un rôle qui n'a pas accès à la route
 * courante", utilisée par PostLoginRedirect ET le fallback de chaque garde
 * (RequireInstrumentist/RequireSurgeon/RequireManager). Avant ce lot, chaque garde avait
 * son propre fallback figé (ex. RequireInstrumentist renvoyait tout non-INSTRUMENTIST vers
 * /app/m/dashboard, sans condition) — pour un SURGEON visitant une route /app/i/*, ceci
 * provoquait une boucle de redirection infinie entre /app/m/dashboard (RequireManager,
 * SURGEON n'est pas desktop) et /app/i/today (RequireInstrumentist, SURGEON n'est pas
 * instrumentiste), qui plantait le rendu (worker vitest crashé lors de la découverte). Ces
 * tests verrouillent qu'un aller simple existe pour chacun des 4 rôles réels.
 */
describe("homePathForRole", () => {
  it("MANAGER → /app/m/dashboard", () => {
    expect(homePathForRole("MANAGER")).toBe("/app/m/dashboard");
  });

  it("ADMIN → /app/m/dashboard", () => {
    expect(homePathForRole("ADMIN")).toBe("/app/m/dashboard");
  });

  it("SURGEON → /app/s", () => {
    expect(homePathForRole("SURGEON")).toBe("/app/s");
  });

  it("INSTRUMENTIST → /app/i/today", () => {
    expect(homePathForRole("INSTRUMENTIST")).toBe("/app/i/today");
  });

  it("rôle inconnu → /app/forbidden (jamais une boucle, jamais un chemin vide)", () => {
    expect(homePathForRole("SOMETHING_ELSE")).toBe("/app/forbidden");
  });

  it("aucun des 4 rôles réels ne peut produire un aller-retour entre deux gardes", () => {
    // Pour chaque rôle réel, sa propre home path doit être stable : rediriger vers
    // homePathForRole(role) puis ré-évaluer homePathForRole du rôle qui GARDE cette
    // route ne doit jamais renvoyer ailleurs — verrouille l'absence de cycle à 2 pas.
    const roles = ["MANAGER", "ADMIN", "SURGEON", "INSTRUMENTIST"];
    for (const role of roles) {
      const home = homePathForRole(role);
      const guardsHome = home.startsWith("/app/m")
        ? isDesktopRole(role)
        : home.startsWith("/app/s")
          ? role === "SURGEON"
          : home.startsWith("/app/i")
            ? isMobileRole(role) && role !== "SURGEON"
            : true;
      expect(guardsHome).toBe(true);
    }
  });
});
