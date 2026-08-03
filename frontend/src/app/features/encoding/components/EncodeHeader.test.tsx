import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { EncodeHeader } from "./EncodeHeader";

describe("EncodeHeader", () => {
  it("affiche le numéro de mission et les tags date/type (site/chirurgien vivent désormais dans MissionReadOnlyCard)", () => {
    render(
      <EncodeHeader
        missionId={529}
        dateLabel="Dimanche 5 juillet 2026"
        typeLabel="Bloc opératoire"
        onBack={vi.fn()}
      />,
    );

    expect(screen.getByText("Mission #529")).toBeInTheDocument();
    expect(screen.getByText("Dimanche 5 juillet 2026")).toBeInTheDocument();
    expect(screen.getByText("Bloc opératoire")).toBeInTheDocument();
  });

  it("n'affiche aucun horodatage tant que savedAt est absent", () => {
    render(
      <EncodeHeader missionId={1} dateLabel="Lundi 1 janvier 2026" typeLabel="Consultation" onBack={vi.fn()} />,
    );
    expect(screen.queryByText(/^\d{2}:\d{2}$/)).not.toBeInTheDocument();
  });

  it("affiche l'horodatage HH:MM quand savedAt est fourni", () => {
    const savedAt = new Date(2026, 0, 1, 9, 46);
    render(
      <EncodeHeader missionId={1} dateLabel="—" typeLabel="Bloc opératoire" onBack={vi.fn()} savedAt={savedAt} />,
    );
    expect(screen.getByText("09:46")).toBeInTheDocument();
  });

  it("appelle onBack au clic sur le bouton retour", async () => {
    const onBack = vi.fn();
    const user = userEvent.setup();
    render(
      <EncodeHeader missionId={1} dateLabel="—" typeLabel="Bloc opératoire" onBack={onBack} />,
    );

    await user.click(screen.getByLabelText("Retour"));
    expect(onBack).toHaveBeenCalledTimes(1);
  });

  it("les vagues décoratives ont un attribut d HTML statique (repli Safari, qui ne supporte pas la propriété CSS d utilisée pour l'animation)", () => {
    const { container } = render(
      <EncodeHeader missionId={1} dateLabel="—" typeLabel="Bloc opératoire" onBack={vi.fn()} />,
    );
    const wavePaths = container.querySelectorAll("path");
    expect(wavePaths.length).toBeGreaterThanOrEqual(2);
    wavePaths.forEach((path) => {
      expect(path.getAttribute("d")).toBeTruthy();
    });
  });
});
