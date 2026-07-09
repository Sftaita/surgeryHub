import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { EncodeHeader } from "./EncodeHeader";

describe("EncodeHeader", () => {
  it("affiche le numéro de mission, le site et les tags date/type", () => {
    render(
      <EncodeHeader
        missionId={529}
        siteName="CHU Brugmann"
        siteAddress="Site Victor Horta"
        personLine="Dr. Anouk Peeters"
        dateLabel="Dimanche 5 juillet 2026"
        typeLabel="Bloc opératoire"
        onBack={vi.fn()}
      />,
    );

    expect(screen.getByText("Mission #529")).toBeInTheDocument();
    expect(screen.getByText(/CHU Brugmann — Site Victor Horta/)).toBeInTheDocument();
    expect(screen.getByText("Dr. Anouk Peeters")).toBeInTheDocument();
    expect(screen.getByText("Dimanche 5 juillet 2026")).toBeInTheDocument();
    expect(screen.getByText("Bloc opératoire")).toBeInTheDocument();
  });

  it("n'affiche pas de 2e ligne quand personLine est absent", () => {
    render(
      <EncodeHeader
        missionId={1}
        siteName="Site X"
        dateLabel="Lundi 1 janvier 2026"
        typeLabel="Consultation"
        onBack={vi.fn()}
      />,
    );
    expect(screen.queryByText(/Dr\./)).not.toBeInTheDocument();
  });

  it("appelle onBack au clic sur le bouton retour", async () => {
    const onBack = vi.fn();
    const user = userEvent.setup();
    render(
      <EncodeHeader missionId={1} siteName="Site X" dateLabel="—" typeLabel="Bloc opératoire" onBack={onBack} />,
    );

    await user.click(screen.getByLabelText("Retour"));
    expect(onBack).toHaveBeenCalledTimes(1);
  });
});
