import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import InboxIcon from "@mui/icons-material/Inbox";
import { EmptyState } from "./EmptyState";

describe("EmptyState", () => {
  it("renders the title, and description/action only when provided", () => {
    render(<EmptyState title="Aucun résultat." />);
    expect(screen.getByText("Aucun résultat.")).toBeInTheDocument();
    expect(screen.queryByRole("button")).toBeNull();
  });

  it("renders description and icon when provided", () => {
    render(<EmptyState title="Rien ici" description="Essayez un autre filtre." icon={InboxIcon} />);
    expect(screen.getByText("Rien ici")).toBeInTheDocument();
    expect(screen.getByText("Essayez un autre filtre.")).toBeInTheDocument();
  });

  it("renders an action button and calls onClick", async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<EmptyState title="Vide" action={{ label: "Ajouter", onClick }} />);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it("supports the dashed variant without changing content", () => {
    render(<EmptyState title="Vide" variant="dashed" />);
    expect(screen.getByText("Vide")).toBeInTheDocument();
  });
});
