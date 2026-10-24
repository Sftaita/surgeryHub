import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { AUTH_STORAGE_KEY, clearAuth, isPersistentAuth, readAuth, writeAuth } from "./authStorage";

// D-157 — one session per browser, shared by its tabs: a duplicated tab must never keep its own
// stale copy of the refresh-token chain (the server takes it for a replay and revokes the session).
const COOKIE = "surgicalhub.auth.bs";
const closeBrowser = () => {
  document.cookie = `${COOKIE}=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
  sessionStorage.clear();
};

describe("authStorage (D-157)", () => {
  beforeEach(() => {
    localStorage.clear();
    closeBrowser();
  });
  afterEach(() => vi.restoreAllMocks());

  it("appareil partagé : un onglet dupliqué lit la même chaîne de jetons et voit chaque rotation", () => {
    writeAuth({ accessToken: "a0", refreshToken: "r0" }, false);
    const duplicatedTabCopy = sessionStorage.getItem(AUTH_STORAGE_KEY); // what "Duplicate tab" copies
    expect(duplicatedTabCopy).toBeNull();

    writeAuth({ accessToken: "a1", refreshToken: "r1" }); // rotation in tab 1
    sessionStorage.clear(); // tab 2: its own sessionStorage, same browser
    expect(readAuth()).toEqual({ accessToken: "a1", refreshToken: "r1" });
    expect(isPersistentAuth()).toBe(false);
  });

  it("appareil partagé : la session se termine à la fermeture du navigateur", () => {
    writeAuth({ accessToken: "a", refreshToken: "r" }, false);
    closeBrowser();
    expect(readAuth()).toBeNull();
    expect(localStorage.getItem(AUTH_STORAGE_KEY)).toBeNull();
  });

  it("le cookie de session du navigateur ne contient jamais de jeton", () => {
    writeAuth({ accessToken: "secret-access", refreshToken: "secret-refresh" }, false);
    expect(document.cookie).toMatch(new RegExp(`${COOKIE}=[0-9a-f]{32}`));
    expect(document.cookie).not.toContain("secret");
  });

  it("« Rester connecté » : survit à la fermeture du navigateur ; une session d'avant D-157 reste valable", () => {
    writeAuth({ accessToken: "a", refreshToken: "r" }, true);
    closeBrowser();
    expect(readAuth()).toEqual({ accessToken: "a", refreshToken: "r" });

    localStorage.setItem(AUTH_STORAGE_KEY, JSON.stringify({ accessToken: "old", refreshToken: "old-r" }));
    expect(readAuth()).toEqual({ accessToken: "old", refreshToken: "old-r" });
    expect(isPersistentAuth()).toBe(true);
  });

  it("une rotation ne change jamais le type de session", () => {
    writeAuth({ accessToken: "a", refreshToken: "r" }, true);
    writeAuth({ accessToken: "a2", refreshToken: "r2" });
    expect(isPersistentAuth()).toBe(true);

    writeAuth({ accessToken: "b", refreshToken: "s" }, false);
    writeAuth({ accessToken: "b2", refreshToken: "s2" });
    expect(isPersistentAuth()).toBe(false);
    expect(readAuth()?.refreshToken).toBe("s2");
  });

  it("cookies bloqués : la session non persistante reste dans l'onglet (sessionStorage)", () => {
    vi.spyOn(document, "cookie", "get").mockReturnValue("");
    vi.spyOn(document, "cookie", "set").mockImplementation(() => {});
    writeAuth({ accessToken: "a", refreshToken: "r" }, false);
    expect(localStorage.getItem(AUTH_STORAGE_KEY)).toBeNull();
    expect(readAuth()).toEqual({ accessToken: "a", refreshToken: "r" });
    writeAuth({ accessToken: "a2", refreshToken: "r2" });
    expect(JSON.parse(sessionStorage.getItem(AUTH_STORAGE_KEY) ?? "{}").refreshToken).toBe("r2");
  });

  it("déconnexion : plus aucune session, dans aucun stockage", () => {
    writeAuth({ accessToken: "a", refreshToken: "r" }, false);
    clearAuth();
    expect(readAuth()).toBeNull();
  });
});
