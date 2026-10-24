import { describe, it, expect, vi } from "vitest";
import { handleStaleBundle, installStaleBundleRecovery } from "./staleBundleRecovery";

function memoryStorage(initial: Record<string, string> = {}) {
  const data = { ...initial };
  return { getItem: (k: string) => data[k] ?? null, setItem: (k: string, v: string) => { data[k] = v; }, data };
}

describe("staleBundleRecovery (D-163) — chunk d'un ancien bundle après un déploiement", () => {
  it("recharge une fois et empêche l'erreur de remonter", () => {
    const storage = memoryStorage();
    const reload = vi.fn();
    const event = new Event("vite:preloadError", { cancelable: true });

    expect(handleStaleBundle(event, storage, reload, 1_000_000)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(event.defaultPrevented).toBe(true);
    expect(storage.data["surgicalhub.staleBundleReloadAt"]).toBe("1000000");
  });

  it("ne boucle jamais : un second échec dans les 30 s laisse l'erreur remonter", () => {
    const storage = memoryStorage({ "surgicalhub.staleBundleReloadAt": "1000000" });
    const reload = vi.fn();
    const event = new Event("vite:preloadError", { cancelable: true });

    expect(handleStaleBundle(event, storage, reload, 1_010_000)).toBe(false);
    expect(reload).not.toHaveBeenCalled();
    expect(event.defaultPrevented).toBe(false);
  });

  it("un nouvel échec bien plus tard (déploiement suivant) recharge de nouveau", () => {
    const storage = memoryStorage({ "surgicalhub.staleBundleReloadAt": "1000000" });
    const reload = vi.fn();
    expect(handleStaleBundle(new Event("vite:preloadError", { cancelable: true }), storage, reload, 1_000_000 + 60_000)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("stockage indisponible : recharge quand même, sans planter", () => {
    const broken = { getItem: () => { throw new Error("denied"); }, setItem: () => { throw new Error("denied"); } };
    const reload = vi.fn();
    expect(handleStaleBundle(new Event("vite:preloadError", { cancelable: true }), broken, reload, 5)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("s'abonne à l'événement Vite", () => {
    const addEventListener = vi.fn();
    installStaleBundleRecovery({ addEventListener } as unknown as Window);
    expect(addEventListener).toHaveBeenCalledWith("vite:preloadError", expect.any(Function));
  });
});
