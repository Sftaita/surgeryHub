import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { StatusBadge, ActiveBadge } from "./StatusBadge";

type Status = "PENDING" | "DONE";

describe("StatusBadge", () => {
  it("renders the configured label for the given status", () => {
    render(
      <StatusBadge<Status>
        status="PENDING"
        config={{ PENDING: { label: "En attente", color: "warning" }, DONE: { label: "Terminé", color: "success" } }}
      />,
    );
    expect(screen.getByText("En attente")).toBeInTheDocument();
  });

  it("falls back to the raw status when no config entry matches", () => {
    render(<StatusBadge<Status> status="DONE" config={{ PENDING: { label: "En attente", color: "warning" } } as any} />);
    expect(screen.getByText("DONE")).toBeInTheDocument();
  });

  it("calls onClick when clickable", async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(
      <StatusBadge<Status>
        status="DONE"
        config={{ PENDING: { label: "En attente", color: "warning" }, DONE: { label: "Terminé", color: "success" } }}
        onClick={onClick}
      />,
    );
    await user.click(screen.getByText("Terminé"));
    expect(onClick).toHaveBeenCalledTimes(1);
  });
});

describe("ActiveBadge", () => {
  it("shows the active label by default", () => {
    render(<ActiveBadge active />);
    expect(screen.getByText("Actif")).toBeInTheDocument();
  });

  it("shows the inactive label when not active", () => {
    render(<ActiveBadge active={false} />);
    expect(screen.getByText("Inactif")).toBeInTheDocument();
  });

  it("supports custom labels", () => {
    render(<ActiveBadge active activeLabel="Ouvert" inactiveLabel="Fermé" />);
    expect(screen.getByText("Ouvert")).toBeInTheDocument();
  });
});
