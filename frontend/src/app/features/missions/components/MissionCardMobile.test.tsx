import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import MissionCardMobile from "./MissionCardMobile";
import type { Mission } from "../api/missions.types";

function makeMission(overrides: Partial<Mission> = {}): Mission {
  return {
    id: 1,
    status: "ASSIGNED",
    startAt: "2026-08-01T08:00:00Z",
    endAt: "2026-08-01T12:00:00Z",
    site: { id: 1, name: "Clinique Test" },
    surgeon: { firstname: "Jean", lastname: "Dupont" },
    ...overrides,
  } as Mission;
}

describe("MissionCardMobile — zones tactiles des CTA (Lot 5, audit PWA/mobile/admin 2026-07-29)", () => {
  it("le bouton d'action principale a une hauteur minimale de 44px (auparavant size=small ~30px)", () => {
    render(
      <MissionCardMobile
        mission={makeMission()}
        primaryAction={{ label: "Valider", action: vi.fn(), visible: true }}
      />,
    );

    const button = screen.getByRole("button", { name: "Valider" });
    expect(button).toHaveStyle({ minHeight: "44px" });
  });

  it("le bouton d'action secondaire a aussi une hauteur minimale de 44px", () => {
    render(
      <MissionCardMobile
        mission={makeMission()}
        secondaryAction={{ label: "Refuser", action: vi.fn(), visible: true }}
      />,
    );

    const button = screen.getByRole("button", { name: "Refuser" });
    expect(button).toHaveStyle({ minHeight: "44px" });
  });

  it("n'affiche aucune action quand aucune n'est visible", () => {
    render(<MissionCardMobile mission={makeMission()} />);
    expect(screen.queryByRole("button")).toBeNull();
  });
});
