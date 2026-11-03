import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";

import { PrestationDecisionNotice, prestationDecisionErrorText, referenceNotice } from "./PrestationDecisionNotice";
import { EncodingCorrectionDialog } from "../../encoding-tracking/components/EncodingCorrectionDialog";

// Complément D-156 — les décisions du manager portent sur toute la prestation multi-salles.

describe("PrestationDecisionNotice", () => {
  it("annonce que la décision s'applique à toute la prestation", () => {
    render(<PrestationDecisionNotice prestation={{ missionIds: [487, 488] }} />);
    expect(screen.getByTestId("prestation-decision-notice")).toHaveTextContent(
      "Prestation multi-salles · 2 missions (n° 487, 488) : cette décision s'applique à toute la prestation.",
    );
  });

  it("n'affiche rien pour une mission seule", () => {
    const { container } = render(<PrestationDecisionNotice prestation={null} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("la fenêtre de renvoi en correction le dit aussi", () => {
    render(
      <EncodingCorrectionDialog
        kind="RETURN"
        missionLabel="#488"
        recipient={{ role: "INSTRUMENTIST", name: "Salve Decorte" }}
        statusLabel="Soumise"
        pending={false}
        error={null}
        onConfirm={() => undefined}
        onCancel={() => undefined}
        prestation={{ missionIds: [487, 488] }}
      />,
    );
    expect(screen.getByTestId("prestation-decision-notice")).toHaveTextContent("toute la prestation");
  });
});

describe("referenceNotice / prestationDecisionErrorText", () => {
  it("explique où s'ajoute le contenu, seulement hors de la mission de référence", () => {
    expect(referenceNotice(null)).toBeNull();
    expect(referenceNotice({ referenceMissionId: 487, acceptsNewContent: true })).toBeNull();
    expect(referenceNotice({ referenceMissionId: 487, acceptsNewContent: false })).toContain("mission de référence n° 487");
  });

  it("relaie le message serveur d'un refus de prestation, jamais un autre", () => {
    const refusal = { response: { status: 409, data: { error: { code: "PRESTATION_STATUS_MISMATCH", message: "Une partie de la prestation multi-salles est déjà validée." } } } };
    expect(prestationDecisionErrorText(refusal)).toBe("Une partie de la prestation multi-salles est déjà validée.");
    expect(prestationDecisionErrorText({ response: { status: 409, data: { error: { code: "OTHER", message: "x" } } } })).toBeNull();
  });
});
