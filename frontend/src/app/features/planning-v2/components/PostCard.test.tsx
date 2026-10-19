import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";

import { PostCard } from "./PostCard";
import type { SurgeonSchedulePostV2 } from "../api/planningV2.types";

function post(overrides: Partial<SurgeonSchedulePostV2> = {}): SurgeonSchedulePostV2 {
  return {
    id: 13,
    surgeon: { id: 10, email: "s@x", name: "Dr Martin" },
    site: { id: 1, name: "Delta" },
    type: "BLOCK",
    period: "MATIN",
    instrumentist: { id: 20, email: "i@x", name: "Iris Lambert" },
    instrumentistBlocked: false,
    startDate: "2026-07-01",
    endDate: null,
    active: true,
    recurrence: { frequency: "WEEKLY", interval: 2, weekdays: [4], anchorDate: "2026-07-02" },
    ...overrides,
  } as SurgeonSchedulePostV2;
}

function renderCard(p: SurgeonSchedulePostV2) {
  return render(<PostCard post={p} onEdit={vi.fn()} onToggleActive={vi.fn()} onManageExceptions={vi.fn()} />);
}

describe("D-152 — PostCard, default instrumentist incompatible with the surgeon", () => {
  it("flags the post « Bloqué — instrumentiste incompatible » with an accessible label, so the manager corrects it", () => {
    renderCard(post({ instrumentistBlocked: true }));

    expect(screen.getByTestId("post-13-blocked")).toHaveTextContent("Bloqué — instrumentiste incompatible");
    expect(screen.getByLabelText("Iris Lambert — Bloqué, instrumentiste incompatible")).toBeInTheDocument();
  });

  it("shows nothing special for a compatible default instrumentist", () => {
    renderCard(post());

    expect(screen.getByText("Iris Lambert")).toBeInTheDocument();
    expect(screen.queryByTestId("post-13-blocked")).not.toBeInTheDocument();
  });
});
