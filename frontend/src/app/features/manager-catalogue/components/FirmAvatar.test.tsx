import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { FirmAvatar } from "./FirmAvatar";

/**
 * Seule implémentation logo/fallback-initiales pour une firme (docs/design/screens/
 * catalogue-prestations/README.md, §3/§12) — wrap PersonAvatar, résout logoPath via
 * resolveApiAssetUrl (chemin racine-relatif -> URL absolue).
 */
describe("FirmAvatar", () => {
  it("affiche les initiales de repli quand aucun logo n'est enregistré", () => {
    render(<FirmAvatar name="Smith & Nephew" logoPath={null} />);
    expect(screen.getByText("SN")).toBeInTheDocument();
  });

  it("affiche l'image du logo quand logoPath est fourni", () => {
    render(<FirmAvatar name="ConMed" logoPath="/uploads/firm-logos/x.png" />);
    const img = screen.getByRole("img", { name: "ConMed" });
    expect(img).toHaveAttribute("src", expect.stringContaining("/uploads/firm-logos/x.png"));
  });
});
