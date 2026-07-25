import { describe, it, expect, vi } from "vitest";
import { renderHook } from "@testing-library/react";
import { usePwaInstallMenuState } from "./usePwaInstallMenuState";

let mockValue: any;
vi.mock("./PwaInstallProvider", () => ({
  usePwaInstall: () => mockValue,
}));

describe("usePwaInstallMenuState — point d'entrée permanent (§7)", () => {
  it("déjà installée → 'Application installée', aucune action", () => {
    mockValue = { isInstalled: true, platform: "other", canPromptAndroid: false, openIosGuide: vi.fn(), promptAndroidInstall: vi.fn() };
    const { result } = renderHook(() => usePwaInstallMenuState());
    expect(result.current.label).toBe("Application installée");
    expect(result.current.onAction).toBeNull();
    expect(result.current.variant).toBe("installed");
  });

  it("iOS non installé → déclenche le guide iOS", () => {
    const openIosGuide = vi.fn();
    mockValue = { isInstalled: false, platform: "ios", canPromptAndroid: false, openIosGuide, promptAndroidInstall: vi.fn() };
    const { result } = renderHook(() => usePwaInstallMenuState());
    expect(result.current.actionLabel).toBe("Voir comment faire");
    result.current.onAction?.();
    expect(openIosGuide).toHaveBeenCalledTimes(1);
    expect(result.current.variant).toBe("actionable");
  });

  it("Android/desktop avec prompt disponible → déclenche promptAndroidInstall", () => {
    const promptAndroidInstall = vi.fn().mockResolvedValue("accepted");
    mockValue = { isInstalled: false, platform: "android", canPromptAndroid: true, openIosGuide: vi.fn(), promptAndroidInstall };
    const { result } = renderHook(() => usePwaInstallMenuState());
    expect(result.current.actionLabel).toBe("Installer");
    result.current.onAction?.();
    expect(promptAndroidInstall).toHaveBeenCalledTimes(1);
    expect(result.current.variant).toBe("actionable");
  });

  it("navigateur non compatible / pas de prompt actif → explication, pas d'action", () => {
    mockValue = { isInstalled: false, platform: "other", canPromptAndroid: false, openIosGuide: vi.fn(), promptAndroidInstall: vi.fn() };
    const { result } = renderHook(() => usePwaInstallMenuState());
    expect(result.current.onAction).toBeNull();
    expect(result.current.disabled).toBe(true);
    expect(result.current.variant).toBe("unavailable");
  });
});
