import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { buildProfilePictureUrl } from "./instrumentists.utils";

describe("buildProfilePictureUrl", () => {
  beforeEach(() => {
    vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("retourne undefined pour null", () => {
    expect(buildProfilePictureUrl(null)).toBeUndefined();
  });

  it("retourne undefined pour une chaîne vide", () => {
    expect(buildProfilePictureUrl("")).toBeUndefined();
  });

  it("retourne undefined pour undefined", () => {
    expect(buildProfilePictureUrl(undefined)).toBeUndefined();
  });

  it("préfixe un chemin relatif avec VITE_API_BASE_URL", () => {
    expect(buildProfilePictureUrl("/uploads/profile-pictures/user-1.jpg")).toBe(
      "https://api.surgicalhub.test/uploads/profile-pictures/user-1.jpg",
    );
  });

  it("évite les doubles slashs entre la base et le chemin", () => {
    vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test/");
    expect(buildProfilePictureUrl("/uploads/profile-pictures/user-1.jpg")).toBe(
      "https://api.surgicalhub.test/uploads/profile-pictures/user-1.jpg",
    );
  });

  it("conserve une URL absolue http déjà complète", () => {
    expect(buildProfilePictureUrl("http://cdn.example.com/photo.jpg")).toBe(
      "http://cdn.example.com/photo.jpg",
    );
  });

  it("conserve une URL absolue https déjà complète", () => {
    expect(buildProfilePictureUrl("https://cdn.example.com/photo.jpg")).toBe(
      "https://cdn.example.com/photo.jpg",
    );
  });
});
