import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { resolveApiAssetUrl } from "./apiAssetUrl";

describe("resolveApiAssetUrl", () => {
  beforeEach(() => {
    vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("retourne undefined pour null", () => {
    expect(resolveApiAssetUrl(null)).toBeUndefined();
  });

  it("retourne undefined pour une chaîne vide", () => {
    expect(resolveApiAssetUrl("")).toBeUndefined();
  });

  it("retourne undefined pour undefined", () => {
    expect(resolveApiAssetUrl(undefined)).toBeUndefined();
  });

  it("préfixe un chemin racine-relatif avec VITE_API_BASE_URL", () => {
    expect(resolveApiAssetUrl("/uploads/hospital-photos/hospital-1.jpg")).toBe(
      "https://api.surgicalhub.test/uploads/hospital-photos/hospital-1.jpg",
    );
  });

  it("préfixe un chemin relatif sans slash initial", () => {
    expect(resolveApiAssetUrl("uploads/hospital-photos/hospital-1.jpg")).toBe(
      "https://api.surgicalhub.test/uploads/hospital-photos/hospital-1.jpg",
    );
  });

  it("évite les doubles slashs entre la base et le chemin", () => {
    vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test/");
    expect(resolveApiAssetUrl("/uploads/profile-pictures/user-1.jpg")).toBe(
      "https://api.surgicalhub.test/uploads/profile-pictures/user-1.jpg",
    );
  });

  it("conserve une URL absolue http déjà complète", () => {
    expect(resolveApiAssetUrl("http://cdn.example.com/photo.jpg")).toBe(
      "http://cdn.example.com/photo.jpg",
    );
  });

  it("conserve une URL absolue https déjà complète", () => {
    expect(resolveApiAssetUrl("https://cdn.example.com/photo.jpg")).toBe(
      "https://cdn.example.com/photo.jpg",
    );
  });

  it("ne produit pas une URL invalide quand VITE_API_BASE_URL est absent", () => {
    vi.stubEnv("VITE_API_BASE_URL", "");
    // Root-relative — le navigateur la résout contre l'origine courante, jamais "invalide".
    expect(resolveApiAssetUrl("/uploads/hospital-photos/hospital-1.jpg")).toBe(
      "/uploads/hospital-photos/hospital-1.jpg",
    );
  });
});
