import { describe, it, expect } from "vitest";
import { QueryClient } from "@tanstack/react-query";
import { invalidateOperationalPlanning } from "./missionDispatch.api";

// D-156 lot 1 — one mutation, every operational view refreshed (Mission = source of truth).
describe("invalidateOperationalPlanning", () => {
  it("invalide toutes les vues du planning opérationnel, quel que soit l'écran d'origine", async () => {
    const client = new QueryClient();
    const keys = [
      ["missions", "by-day", {}],
      ["planning-schedule", "2026-11-01", "2026-12-01", ""],
      ["planning-v2", "modification-missions", 12],
      ["planning-v2", "alerts", "list", "unfiltered"],
      ["coverage-summary", 12],
      ["dashboard", "missions-today"],
      ["instrumentist-planning", 5, "a", "b"],
      ["surgeon-planning", 6, "a", "b"],
      ["mission", 42],
      ["missionEncoding", 42],
    ];
    keys.forEach((k) => client.setQueryData(k, { ok: true }));
    const untouched = ["sites"];
    client.setQueryData(untouched, []);

    await invalidateOperationalPlanning(client, 42);

    keys.forEach((k) => expect(client.getQueryState(k)?.isInvalidated, JSON.stringify(k)).toBe(true));
    expect(client.getQueryState(untouched)?.isInvalidated).toBe(false);
  });
});
