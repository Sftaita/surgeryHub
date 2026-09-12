import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { HoursCell } from "./HoursCell";
import type { EncodingTrackingHours } from "../api/encodingTracking.api";

describe("HoursCell", () => {
  it("PLANNED : affiche uniquement le planifié, jamais un « effectif » fantôme", () => {
    const hours: EncodingTrackingHours = { plannedMinutes: 180, effectiveMinutes: 180, effectiveSource: "PLANNED", hasRealHours: false };
    render(<HoursCell hours={hours} />);
    expect(screen.getByText("3 h planifiées")).toBeInTheDocument();
    expect(screen.getByText("Planifié")).toBeInTheDocument();
    expect(screen.queryByText(/effectives/)).toBeNull();
  });

  it("ACTUAL_TIMES : affiche planifié et effectif côte à côte avec la source", () => {
    const hours: EncodingTrackingHours = { plannedMinutes: 300, effectiveMinutes: 312, effectiveSource: "ACTUAL_TIMES", hasRealHours: true };
    render(<HoursCell hours={hours} />);
    expect(screen.getByText("5 h planifiées · 5 h 12 effectives")).toBeInTheDocument();
    expect(screen.getByText("Heures réelles")).toBeInTheDocument();
  });

  it("ACTUAL_EXPLICIT : affiche la source « Heures explicites »", () => {
    const hours: EncodingTrackingHours = { plannedMinutes: 120, effectiveMinutes: 145, effectiveSource: "ACTUAL_EXPLICIT", hasRealHours: true };
    render(<HoursCell hours={hours} />);
    expect(screen.getByText("Heures explicites")).toBeInTheDocument();
  });

  it("n'invente jamais de gate sur le statut SUBMITTED — hasRealHours seul pilote l'affichage", () => {
    // hasRealHours=true doit afficher l'effectif quel que soit le statut mission, qui
    // n'est même pas un paramètre de ce composant.
    const hours: EncodingTrackingHours = { plannedMinutes: 60, effectiveMinutes: 75, effectiveSource: "ACTUAL_TIMES", hasRealHours: true };
    render(<HoursCell hours={hours} />);
    expect(screen.getByText(/1 h 15 effectives/)).toBeInTheDocument();
  });
});
