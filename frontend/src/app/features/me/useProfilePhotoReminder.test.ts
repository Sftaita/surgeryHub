import { describe, it, expect, beforeEach, vi } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { isReminderDue, useProfilePhotoReminder } from "./useProfilePhotoReminder";

const DAY_MS = 24 * 60 * 60 * 1000;

beforeEach(() => {
  localStorage.clear();
});

describe("isReminderDue (cadence pure function)", () => {
  it("est dû quand il n'y a jamais eu de rejet", () => {
    expect(isReminderDue(null)).toBe(true);
  });

  it("n'est pas dû avant 7 jours après le 1er rejet", () => {
    const now = Date.now();
    const state = { dismissedAt: now, dismissCount: 1 };
    expect(isReminderDue(state, now + 6 * DAY_MS)).toBe(false);
    expect(isReminderDue(state, now + 7 * DAY_MS)).toBe(true);
  });

  it("attend 14 jours après le 2e rejet", () => {
    const now = Date.now();
    const state = { dismissedAt: now, dismissCount: 2 };
    expect(isReminderDue(state, now + 13 * DAY_MS)).toBe(false);
    expect(isReminderDue(state, now + 14 * DAY_MS)).toBe(true);
  });

  it("attend 30 jours après le 3e rejet", () => {
    const now = Date.now();
    const state = { dismissedAt: now, dismissCount: 3 };
    expect(isReminderDue(state, now + 29 * DAY_MS)).toBe(false);
    expect(isReminderDue(state, now + 30 * DAY_MS)).toBe(true);
  });

  it("ne redevient jamais dû après le 4e rejet, même longtemps après", () => {
    const now = Date.now();
    const state = { dismissedAt: now, dismissCount: 4 };
    expect(isReminderDue(state, now + 365 * DAY_MS)).toBe(false);
  });
});

describe("useProfilePhotoReminder", () => {
  it("est dû par défaut pour un utilisateur jamais vu", () => {
    const { result } = renderHook(() => useProfilePhotoReminder(1));
    expect(result.current.isDue).toBe(true);
  });

  it("dismiss() persiste en localStorage (pas sessionStorage) et n'est plus dû immédiatement après", () => {
    const { result } = renderHook(() => useProfilePhotoReminder(1));

    act(() => result.current.dismiss());

    expect(result.current.isDue).toBe(false);
    expect(localStorage.getItem("surgicalhub.profilePhotoPrompt.1")).not.toBeNull();
  });

  it("un nouveau montage du hook relit l'état persisté (survit à la fermeture de l'onglet)", () => {
    const { result: first } = renderHook(() => useProfilePhotoReminder(1));
    act(() => first.current.dismiss());

    const { result: second } = renderHook(() => useProfilePhotoReminder(1));
    expect(second.current.isDue).toBe(false);
  });

  it("isole les états par utilisateur", () => {
    const { result: userA } = renderHook(() => useProfilePhotoReminder(1));
    act(() => userA.current.dismiss());

    const { result: userB } = renderHook(() => useProfilePhotoReminder(2));
    expect(userB.current.isDue).toBe(true);
  });

  it("incrémente dismissCount à chaque rejet jusqu'à l'arrêt après le 4e", () => {
    vi.useFakeTimers();
    const { result } = renderHook(() => useProfilePhotoReminder(1));

    for (let i = 0; i < 4; i++) {
      act(() => result.current.dismiss());
      act(() => vi.advanceTimersByTime(31 * DAY_MS));
    }

    expect(result.current.isDue).toBe(false);
    vi.useRealTimers();
  });
});
