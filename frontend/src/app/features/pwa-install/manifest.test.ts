import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

const manifestPath = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../../../../public/manifest.json",
);

/**
 * Correctif PWA / Se souvenir de moi (2026-09-18) — une PWA installée (desktop, Android,
 * iOS) doit démarrer sur /login, jamais sur la home publique ("/"). `start_url` est la
 * seule chose que l'OS/le navigateur lit au lancement standalone — aucune logique React
 * ne peut la compenser après coup si elle pointe ailleurs. `scope` est fixé explicitement
 * à "/" pour ne pas dépendre du calcul par défaut (dirname de start_url) d'un navigateur
 * à l'autre, et pour que le reste de l'app (hors /login) reste dans le scope PWA.
 */
describe("manifest.json — démarrage PWA", () => {
  const manifest = JSON.parse(readFileSync(manifestPath, "utf-8"));

  it("démarre sur /login, pas sur la home publique", () => {
    expect(manifest.start_url).toBe("/login");
  });

  it("garde un scope couvrant toute l'application", () => {
    expect(manifest.scope).toBe("/");
  });

  it("reste en mode standalone (comportement PWA préservé)", () => {
    expect(manifest.display).toBe("standalone");
  });
});
