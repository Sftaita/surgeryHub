import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { AlertCard } from "./AlertCard";
import type { PlanningAlertV2 } from "../api/planningV2.types";

function makeAlert(overrides: Partial<PlanningAlertV2> = {}): PlanningAlertV2 {
  return {
    id: 1,
    type: "SURGEON_CONFLICT",
    status: "OPEN",
    detectedAt: "2026-10-16T09:00:00+02:00",
    resolvedAt: null,
    resolvedBy: null,
    resolutionNote: null,
    mission: {
      id: 501,
      status: "ASSIGNED",
      startAt: "2026-10-16T08:00:00+02:00",
      endAt: "2026-10-16T13:00:00+02:00",
      site: { id: 1, name: "CHIREC" },
      surgeon: { id: 10, email: "surgeon@test.com", name: "Jean Dupont" },
      instrumentist: null,
    },
    absence: null,
    conflict: null,
    actions: {
      canAcknowledge: true,
      canResolve: true,
      canIgnore: true,
      canReassign: false,
      canOpenAsAvailable: false,
      recommendedAction: "REVIEW",
    },
    ...overrides,
  };
}

const noop = vi.fn();

describe("AlertCard — D-091 cross-site conflict rendering", () => {
  it("shows both sites and both time slots for a SURGEON_CONFLICT with conflict data", () => {
    const alert = makeAlert({
      type: "SURGEON_CONFLICT",
      conflict: {
        personName: "Jean Dupont",
        missionSiteName: "CHIREC",
        missionStartAt: "2026-10-16T08:00:00+02:00",
        missionEndAt: "2026-10-16T13:00:00+02:00",
        conflictingMissionId: 502,
        conflictingSiteName: "Clinique Sainte-Anne",
        conflictingStartAt: "2026-10-16T10:00:00+02:00",
        conflictingEndAt: "2026-10-16T14:00:00+02:00",
        crossSite: true,
      },
    });

    render(<AlertCard alert={alert} onAcknowledge={noop} onResolve={noop} onIgnore={noop} onReassign={noop} onOpenAsAvailable={noop} />);

    expect(
      screen.getByText(
        "Jean Dupont déjà prévu(e) sur CHIREC (08:00–13:00) — chevauche Clinique Sainte-Anne (10:00–14:00) le 16 octobre",
      ),
    ).toBeInTheDocument();
  });

  it("shows the conflicting instrumentist's name and both sites for an INSTRUMENTIST_CONFLICT", () => {
    const alert = makeAlert({
      type: "INSTRUMENTIST_CONFLICT",
      mission: {
        id: 601,
        status: "ASSIGNED",
        startAt: "2026-10-16T08:00:00+02:00",
        endAt: "2026-10-16T13:00:00+02:00",
        site: { id: 1, name: "CHIREC" },
        surgeon: { id: 10, email: "surgeon@test.com", name: "Jean Dupont" },
        instrumentist: { id: 20, email: "instr@test.com", name: "Marie Martin" },
      },
      conflict: {
        personName: "Marie Martin",
        missionSiteName: "CHIREC",
        missionStartAt: "2026-10-16T08:00:00+02:00",
        missionEndAt: "2026-10-16T13:00:00+02:00",
        conflictingMissionId: 602,
        conflictingSiteName: "Clinique Sainte-Anne",
        conflictingStartAt: "2026-10-16T11:00:00+02:00",
        conflictingEndAt: "2026-10-16T15:00:00+02:00",
        crossSite: true,
      },
      actions: {
        canAcknowledge: true, canResolve: true, canIgnore: true,
        canReassign: true, canOpenAsAvailable: false, recommendedAction: "REASSIGN",
      },
    });

    render(<AlertCard alert={alert} onAcknowledge={noop} onResolve={noop} onIgnore={noop} onReassign={noop} onOpenAsAvailable={noop} />);

    expect(
      screen.getByText(
        "Marie Martin déjà prévu(e) sur CHIREC (08:00–13:00) — chevauche Clinique Sainte-Anne (11:00–15:00) le 16 octobre",
      ),
    ).toBeInTheDocument();
    // INSTRUMENTIST_CONFLICT allows reassign — the button must be offered.
    expect(screen.getByRole("button", { name: "Réassigner" })).toBeInTheDocument();
  });

  it("falls back to the generic message when no conflict snapshot is present", () => {
    const alert = makeAlert({ type: "SURGEON_CONFLICT", conflict: null });
    render(<AlertCard alert={alert} onAcknowledge={noop} onResolve={noop} onIgnore={noop} onReassign={noop} onOpenAsAvailable={noop} />);
    expect(screen.getByText(/Conflit de planning pour Jean Dupont/)).toBeInTheDocument();
  });

  it("never offers reassign/open-as-available for a SURGEON_CONFLICT — no automatic surgeon reassignment", () => {
    const alert = makeAlert({
      type: "SURGEON_CONFLICT",
      actions: {
        canAcknowledge: true, canResolve: true, canIgnore: true,
        canReassign: false, canOpenAsAvailable: false, recommendedAction: "REVIEW",
      },
    });
    render(<AlertCard alert={alert} onAcknowledge={noop} onResolve={noop} onIgnore={noop} onReassign={noop} onOpenAsAvailable={noop} />);
    expect(screen.queryByRole("button", { name: "Réassigner" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Ouvrir comme mission" })).toBeNull();
  });
});
