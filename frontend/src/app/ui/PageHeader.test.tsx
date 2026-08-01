import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import BusinessIcon from "@mui/icons-material/Business";
import { PageHeader } from "./PageHeader";

describe("PageHeader", () => {
  it("renders the title, and subtitle/help button only when provided", () => {
    render(<PageHeader title="Firmes partenaires" />);
    expect(screen.getByText("Firmes partenaires")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Aide sur cet écran" })).toBeNull();
  });

  it("renders the subtitle when provided", () => {
    render(<PageHeader title="Établissements" subtitle="Hôpitaux et cliniques partenaires." />);
    expect(screen.getByText("Hôpitaux et cliniques partenaires.")).toBeInTheDocument();
  });

  it("renders a help button wired to the given topic when helpTopicId is provided", () => {
    render(<PageHeader title="Firmes partenaires" helpTopicId="firms" />);
    expect(screen.getByRole("button", { name: "Aide sur cet écran" })).toBeInTheDocument();
  });

  it("renders an action button and calls onClick", async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<PageHeader title="Firmes" action={{ label: "Ajouter une firme", onClick }} />);
    await user.click(screen.getByRole("button", { name: "Ajouter une firme" }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it("renders a custom actions node instead of the default action button when both are given", () => {
    render(
      <PageHeader
        title="Missions"
        action={{ label: "Ne doit pas apparaître", onClick: () => {} }}
        actions={<button>Zone d'actions personnalisée</button>}
      />,
    );
    expect(screen.getByText("Zone d'actions personnalisée")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Ne doit pas apparaître" })).toBeNull();
  });

  it("renders the icon when provided", () => {
    const { container } = render(<PageHeader title="Firmes" icon={BusinessIcon} />);
    expect(container.querySelector('[data-testid="BusinessIcon"]')).toBeInTheDocument();
  });
});
