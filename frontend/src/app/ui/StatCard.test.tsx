import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import InboxIcon from "@mui/icons-material/Inbox";
import { StatCard } from "./StatCard";

describe("StatCard", () => {
  it("affiche le libellé et la valeur tels quels, sans les altérer", () => {
    render(<StatCard label="Missions ouvertes" value={42} />);
    expect(screen.getByText("Missions ouvertes")).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();
  });

  it("accepte une valeur sous forme de chaîne sans la reformater", () => {
    render(<StatCard label="Chiffre d'affaires" value="12 450 €" />);
    expect(screen.getByText("12 450 €")).toBeInTheDocument();
  });

  it("n'affiche aucune icône quand absente", () => {
    const { container } = render(<StatCard label="Total" value={1} />);
    expect(container.querySelector("svg")).toBeNull();
  });

  it("affiche l'icône quand fournie", () => {
    const { container } = render(<StatCard label="Total" value={1} icon={InboxIcon} />);
    expect(container.querySelector("svg")).not.toBeNull();
  });

  it("n'affiche aucune puce (hint) quand absente", () => {
    render(<StatCard label="Total" value={1} />);
    expect(screen.queryByText(/./, { selector: ".MuiChip-label" })).toBeNull();
  });

  it("affiche la puce (hint) quand fournie, sans logique de calcul métier — la valeur est fournie telle quelle", () => {
    render(<StatCard label="Demandes" value={3} hint="À traiter" />);
    expect(screen.getByText("À traiter")).toBeInTheDocument();
  });

  it("appelle onClick au clic quand fourni", async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<StatCard label="Total" value={1} onClick={onClick} />);
    await user.click(screen.getByText("Total"));
    expect(onClick).toHaveBeenCalledTimes(1);
  });
});
