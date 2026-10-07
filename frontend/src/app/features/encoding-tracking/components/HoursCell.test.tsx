import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { HoursCell } from "./HoursCell";
import type { EncodingTrackingHours } from "../api/encodingTracking.api";
import { HOURS_COMPARISON_TONE } from "../encodingStateMeta";

/** jsdom normalise les couleurs en rgb(). */
function rgb(hex: string): string {
  const n = parseInt(hex.slice(1), 16);
  return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`;
}

describe("HoursCell", () => {
  it("PLANNED : affiche uniquement le planifié, jamais un « effectif » fantôme, état neutre explicite", () => {
    const hours: EncodingTrackingHours = { plannedMinutes: 180, effectiveMinutes: 180, effectiveSource: "PLANNED", hasRealHours: false, comparison: "NO_REAL_HOURS" };
    const { container } = render(<HoursCell hours={hours} />);
    expect(screen.getByText("3 h planifiées")).toBeInTheDocument();
    expect(screen.getByText("Heures réelles non renseignées")).toBeInTheDocument();
    expect(screen.queryByText(/effectives/)).toBeNull();
    // Neutre : jamais le vert « conforme » pour un planifié de repli.
    expect(container.querySelector("[data-hours-comparison]")?.getAttribute("data-hours-comparison")).toBe("NO_REAL_HOURS");
    expect(screen.getByText("Heures réelles non renseignées")).toHaveStyle({ color: rgb(HOURS_COMPARISON_TONE.NO_REAL_HOURS.fg) });
  });

  it("réel ≤ planifié → vert", () => {
    const hours: EncodingTrackingHours = { plannedMinutes: 300, effectiveMinutes: 280, effectiveSource: "ACTUAL_TIMES", hasRealHours: true, comparison: "WITHIN_PLAN" };
    const { container } = render(<HoursCell hours={hours} />);
    expect(container.textContent).toContain("5 h planifiées · 4 h 40 effectives");
    expect(screen.getByTestId("hours-effective")).toHaveStyle({ color: rgb(HOURS_COMPARISON_TONE.WITHIN_PLAN.fg) });
    expect(container.querySelector("[data-hours-comparison]")?.getAttribute("data-hours-comparison")).toBe("WITHIN_PLAN");
  });

  it("réel > planifié → orange, avec la source", () => {
    const hours: EncodingTrackingHours = { plannedMinutes: 300, effectiveMinutes: 312, effectiveSource: "ACTUAL_TIMES", hasRealHours: true, comparison: "OVER_PLAN" };
    const { container } = render(<HoursCell hours={hours} />);
    expect(container.textContent).toContain("5 h planifiées · 5 h 12 effectives");
    expect(screen.getByText("Heures réelles")).toBeInTheDocument();
    expect(screen.getByTestId("hours-effective")).toHaveStyle({ color: rgb(HOURS_COMPARISON_TONE.OVER_PLAN.fg) });
  });

  it("ACTUAL_EXPLICIT : affiche la source « Heures explicites »", () => {
    const hours: EncodingTrackingHours = { plannedMinutes: 120, effectiveMinutes: 145, effectiveSource: "ACTUAL_EXPLICIT", hasRealHours: true, comparison: "OVER_PLAN" };
    render(<HoursCell hours={hours} />);
    expect(screen.getByText("Heures explicites")).toBeInTheDocument();
  });

  it("la couleur suit hours.comparison du backend, jamais une comparaison recalculée ici", () => {
    // Valeurs volontairement incohérentes : si le composant comparait lui-même les minutes,
    // il afficherait orange. Seul le verdict backend pilote la couleur.
    const hours: EncodingTrackingHours = { plannedMinutes: 60, effectiveMinutes: 75, effectiveSource: "ACTUAL_TIMES", hasRealHours: true, comparison: "WITHIN_PLAN" };
    render(<HoursCell hours={hours} />);
    expect(screen.getByTestId("hours-effective")).toHaveStyle({ color: rgb(HOURS_COMPARISON_TONE.WITHIN_PLAN.fg) });
  });
});
