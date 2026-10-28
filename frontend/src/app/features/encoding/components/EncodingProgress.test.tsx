import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { EncodingProgress } from "./EncodingProgress";

describe("EncodingProgress — message quand tout est encodé (D-172)", () => {
  it("par défaut (instrumentiste) : inchangé", () => {
    render(<EncodingProgress done={2} total={2} />);
    expect(screen.getByText("Tout est encodé. Vous pouvez valider la mission.")).toBeInTheDocument();
  });

  it("chirurgien : l'action offerte est la soumission, jamais une validation", () => {
    render(<EncodingProgress done={1} total={1} readyText="Tout est encodé. Vous pouvez soumettre l'encodage au manager." />);
    expect(screen.getByText("Tout est encodé. Vous pouvez soumettre l'encodage au manager.")).toBeInTheDocument();
    expect(screen.queryByText(/valider la mission/)).toBeNull();
  });
});
