import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { EncodingContentPanel } from "./EncodingContentPanel";
import type { EncodingAnomalyMarks } from "./EncodingContentPanel";
import type { EncodingEntryMaterialLine, MissionEncodingEntry } from "../../encoding/api/encoding.types";

function line(id: number, label: string, quantity: string, unit: string, ref = `REF-${id}`): EncodingEntryMaterialLine {
  return {
    id, missionInterventionId: 1, interventionDraftId: null, quantity, comment: "",
    item: { id: 100 + id, label, referenceCode: ref, unit, isImplant: false, firm: { id: 10, name: "Smith & Nephew" } },
  };
}

function intervention(id: number, label: string, lines: EncodingEntryMaterialLine[]): MissionEncodingEntry {
  return {
    kind: "INTERVENTION", id, requestId: null, orderIndex: id, label, interventionType: null,
    firm: { id: 10, name: "Smith & Nephew" }, requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false,
    materialLines: lines, materialItemRequests: [],
  };
}

// Cas réel de la capture : deux interventions au libellé identique, chacune avec son Fast-Fix.
const ENTRIES: MissionEncodingEntry[] = [
  intervention(1, "Suture d'un ménisque de genou", [line(1, "Fast-Fix", "4.00", "1", "FF-360")]),
  intervention(2, "Suture d'un ménisque de genou", [line(2, "Fast-Fix", "7.00", "1", "FF-360"), line(3, "Ancre", "2.00", "pièce", "AN-5")]),
];

describe("EncodingContentPanel", () => {
  it("vue par défaut « Par intervention » : un bloc distinct et numéroté par intervention", () => {
    render(<EncodingContentPanel entries={ENTRIES} />);

    expect(screen.getByRole("button", { name: "Par intervention" })).toHaveAttribute("aria-pressed", "true");
    const blocks = screen.getAllByTestId("intervention-block");
    expect(blocks).toHaveLength(2);
    expect(within(blocks[0]).getByText("INTERVENTION 1/2")).toBeInTheDocument();
    expect(within(blocks[1]).getByText("INTERVENTION 2/2")).toBeInTheDocument();
  });

  it("deux interventions homonymes ne se confondent pas : chaque bloc ne contient que SON matériel", () => {
    render(<EncodingContentPanel entries={ENTRIES} />);

    const [first, second] = screen.getAllByTestId("intervention-block");
    expect(first).toHaveAccessibleName("Intervention 1 : Suture d'un ménisque de genou");
    expect(second).toHaveAccessibleName("Intervention 2 : Suture d'un ménisque de genou");
    expect(within(first).getAllByTestId("material-row")).toHaveLength(1);
    expect(within(first).getByText("Qté 4")).toBeInTheDocument();
    expect(within(first).queryByText("Ancre")).toBeNull();
    expect(within(second).getAllByTestId("material-row")).toHaveLength(2);
    expect(within(second).getByText("Qté 7")).toBeInTheDocument();
  });

  it("quantité explicite : jamais le couple brut « 4 1 » ; une vraie unité est conservée", () => {
    const { container } = render(<EncodingContentPanel entries={ENTRIES} />);

    expect(container.textContent).not.toMatch(/\b4 1\b/);
    expect(container.textContent).not.toMatch(/\b7 1\b/);
    expect(screen.getByText("Qté 2 · pièce")).toBeInTheDocument();
    // Firme et référence restent lisibles sous le nom du matériel.
    expect(screen.getAllByText("Smith & Nephew · Réf. FF-360").length).toBeGreaterThan(0);
  });

  it("vue « Matériel » : uniquement les lignes, sans cartes d'intervention, origine discrète", async () => {
    const user = userEvent.setup();
    render(<EncodingContentPanel entries={ENTRIES} />);

    await user.click(screen.getByRole("button", { name: "Matériel" }));

    expect(screen.queryAllByTestId("intervention-block")).toHaveLength(0);
    const rows = screen.getAllByTestId("material-row");
    expect(rows).toHaveLength(3);
    expect(within(rows[0]).getByText("Fast-Fix")).toBeInTheDocument();
    expect(within(rows[0]).getByText("Qté 4")).toBeInTheDocument();
    expect(within(rows[0]).getByText("Smith & Nephew · Réf. FF-360")).toBeInTheDocument();
    expect(within(rows[0]).getByText("↳ Intervention 1 · Suture d'un ménisque de genou")).toBeInTheDocument();
    expect(within(rows[2]).getByText("↳ Intervention 2 · Suture d'un ménisque de genou")).toBeInTheDocument();
  });

  it("vue « Interventions » : uniquement la liste des interventions, sans leurs lignes de matériel", async () => {
    const user = userEvent.setup();
    render(<EncodingContentPanel entries={ENTRIES} />);

    await user.click(screen.getByRole("button", { name: "Interventions" }));

    const rows = screen.getAllByTestId("intervention-row");
    expect(rows).toHaveLength(2);
    expect(screen.queryAllByTestId("material-row")).toHaveLength(0);
    expect(screen.queryByText("Fast-Fix")).toBeNull();
    expect(within(rows[1]).getByText("2 réf.")).toBeInTheDocument();
  });

  it("le sélecteur ne permet jamais de désélectionner toutes les vues", async () => {
    const user = userEvent.setup();
    render(<EncodingContentPanel entries={ENTRIES} />);

    await user.click(screen.getByRole("button", { name: "Par intervention" }));

    expect(screen.getByRole("button", { name: "Par intervention" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getAllByTestId("intervention-block")).toHaveLength(2);
  });
});

describe("EncodingContentPanel — anomalies financières (D-138)", () => {
  // Intervention 1 en anomalie (tarif d'intervention), ligne 3 (« Ancre », intervention 2) en anomalie matériel.
  const MARKS: EncodingAnomalyMarks = { interventionIds: new Set([1]), materialLineIds: new Set([3]) };

  it("les éléments signalés par le backend sont marqués dans les trois modes, et seulement eux", async () => {
    const user = userEvent.setup();
    const { container } = render(<EncodingContentPanel entries={ENTRIES} anomalyMarks={MARKS} />);

    // Par intervention : le bloc 1 et la ligne « Ancre » ; ni le bloc 2 ni les Fast-Fix.
    const marked = () => Array.from(container.querySelectorAll("[data-anomaly]")).map((el) => el.getAttribute("data-anchor"));
    expect(marked()).toEqual(["itv-1", "ml-3"]);

    await user.click(screen.getByRole("button", { name: "Matériel" }));
    expect(marked()).toEqual(["ml-3"]);

    // Vue sans matériel : l'anomalie de la ligne 3 se signale sur l'intervention qui la porte.
    await user.click(screen.getByRole("button", { name: "Interventions" }));
    expect(marked()).toEqual(["itv-1", "itv-2"]);
  });

  it("sans anomalie, aucun marqueur", () => {
    const { container } = render(<EncodingContentPanel entries={ENTRIES} />);
    expect(container.querySelectorAll("[data-anomaly]")).toHaveLength(0);
    expect(screen.queryByText("Anomalie")).not.toBeInTheDocument();
  });

  it("« Voir » mène à l'élément dans chaque mode ; une anomalie d'intervention en vue Matériel bascule sur Par intervention", async () => {
    const user = userEvent.setup();
    const scrolled: string[] = [];
    // jsdom n'implémente pas scrollIntoView : on le fournit le temps du test pour l'observer.
    const original = HTMLElement.prototype.scrollIntoView;
    HTMLElement.prototype.scrollIntoView = vi.fn(function (this: HTMLElement) {
      scrolled.push(this.getAttribute("data-anchor") ?? "");
    });

    const { rerender } = render(<EncodingContentPanel entries={ENTRIES} anomalyMarks={MARKS} focus={{ missionInterventionId: 2, materialLineId: 3, nonce: 1 }} />);
    expect(scrolled.at(-1)).toBe("ml-3");

    await user.click(screen.getByRole("button", { name: "Interventions" }));
    rerender(<EncodingContentPanel entries={ENTRIES} anomalyMarks={MARKS} focus={{ missionInterventionId: 2, materialLineId: 3, nonce: 2 }} />);
    expect(scrolled.at(-1)).toBe("itv-2");

    await user.click(screen.getByRole("button", { name: "Matériel" }));
    rerender(<EncodingContentPanel entries={ENTRIES} anomalyMarks={MARKS} focus={{ missionInterventionId: 1, materialLineId: null, nonce: 3 }} />);
    expect(screen.getByRole("button", { name: "Par intervention" })).toHaveAttribute("aria-pressed", "true");
    expect(scrolled.at(-1)).toBe("itv-1");
    HTMLElement.prototype.scrollIntoView = original;
  });
});
