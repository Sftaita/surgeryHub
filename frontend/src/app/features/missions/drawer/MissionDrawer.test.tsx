import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MissionDrawer } from "./MissionDrawer";
import { buildMissionDrawerView } from "./missionDrawerModel";
import type { Mission } from "../api/missions.types";
import type { FinancialCalculation } from "../../financial-calculation/api/financialCalculation.api";
import type { MissionEncodingEntry } from "../../encoding/api/encoding.types";

/** Mission #1084 de la maquette (docs/design/Instruction design/Mission-drawer), en réponses API. */
const mission = {
  id: 1084,
  type: "BLOCK",
  schedulePrecision: "EXACT",
  startAt: "2026-10-23T14:00:00+02:00",
  endAt: "2026-10-23T18:00:00+02:00",
  site: { id: 1, name: "CHIREC — Hôpital Delta" },
  status: "VALIDATED",
  surgeon: { id: 5, firstname: "Yorick", lastname: "Berger", email: "y@x.be" },
  instrumentist: { id: 9, firstname: "Françoise", lastname: "Detrembleur", email: "f@x.be" },
  allowedActions: ["reopen"],
} as Mission;

const material = (id: number, label: string, ref: string, quantity: string) => ({
  id, missionInterventionId: 1, item: { id, label, referenceCode: ref, unit: "pièce", isImplant: false, firm: { id: 3, name: "Arthrex" } }, quantity, comment: "",
});

const entries = [
  {
    kind: "INTERVENTION", id: 1, requestId: null, orderIndex: 0, label: "Plastie du ligament fémoro-patellaire médian du genou",
    interventionType: null, firm: { id: 3, name: "Arthrex" }, requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false,
    representativePresent: false, materialItemRequests: [],
    materialLines: [material(11, "FiberTak® Soft Anchor", "Fibertak", "2.00"), material(12, "Vis Bio-Compression", "BIOCOMPOSITE VIS", "1.00")],
  },
  {
    kind: "INTERVENTION", id: 2, requestId: null, orderIndex: 1, label: "Mosaic plastie du genou",
    interventionType: null, firm: { id: 3, name: "Arthrex" }, requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false,
    representativePresent: false, materialItemRequests: [], materialLines: [],
  },
] as unknown as MissionEncodingEntry[];

const line = (id: number, beneficiaryType: "FIRM" | "INSTRUMENTIST", lineType: string, description: string, total: string, extra: object = {}) => ({
  id, beneficiaryType, beneficiaryFirmId: beneficiaryType === "FIRM" ? 3 : null, beneficiaryInstrumentistId: beneficiaryType === "INSTRUMENTIST" ? 9 : null,
  lineType, sourceType: "X", descriptionSnapshot: description, quantity: "1.0000", durationMinutes: null, unitAmount: total,
  grossAmount: total, adjustmentAmount: "0.00", totalAmount: total, currency: "EUR", effectiveAt: null, warnings: [], snapshot: {}, ...extra,
});

const calculation = {
  id: 77, missionId: 1084, version: 1, status: "CALCULATED", calculatedAt: "2026-10-24T08:41:00+02:00", obsolete: false,
  totalsByCurrency: { EUR: { FIRM: "213.00", INSTRUMENTIST: "325.00" } },
  lines: [
    line(1, "FIRM", "FIRM_INTERVENTION_FEE", "[MPFL] Plastie du ligament fémoro-patellaire médian du genou", "128.00",
      { snapshot: { firmNameSnapshot: "Arthrex", interventionCodeSnapshot: "MPFL", interventionLabelSnapshot: "Plastie du ligament fémoro-patellaire médian du genou" } }),
    line(2, "FIRM", "FIRM_INTERVENTION_FEE", "[MOSAICPLASTIE] Mosaic plastie du genou", "85.00",
      { snapshot: { firmNameSnapshot: "Arthrex", interventionCodeSnapshot: "MOSAICPLASTIE", interventionLabelSnapshot: "Mosaic plastie du genou" } }),
    line(3, "INSTRUMENTIST", "INSTRUMENTIST_HOURLY", "Prestation horaire — Françoise Detrembleur", "325.00",
      { quantity: "5.0000", durationMinutes: 300, unitAmount: "65.00" }),
  ],
} as unknown as FinancialCalculation;

function view() {
  return buildMissionDrawerView({
    mission,
    execution: {
      missionId: 1084, hasExecutionRecord: true, actualStartAt: "2026-10-23T14:00:00+02:00", actualEndAt: "2026-10-23T19:00:00+02:00",
      actualDurationMinutes: 300, hoursSource: "INSTRUMENTIST", effectiveDurationMinutes: 300, effectiveDurationSource: "ACTUAL_TIMES", disputes: [],
    },
    entries,
    encodingError: false,
    audit: [
      { eventType: "MISSION_ENCODING_COMPLETED", occurredAt: "2026-10-23T21:13:00+02:00", actorId: 9, actorName: "Françoise Detrembleur", payload: null },
      { eventType: "MISSION_ENCODING_VALIDATED", occurredAt: "2026-10-24T08:39:00+02:00", actorId: 1, actorName: "Samy Ftaita", payload: null },
    ],
    calculation,
    calculationLoading: false,
  });
}

function renderDrawer(open = true) {
  return render(
    <MissionDrawer open={open} missionId={1084} view={view()} footer={{ title: "Encodage validé", actions: [] }} onClose={vi.fn()} />,
  );
}

describe("MissionDrawer — recette maquette Mission-drawer (#1084)", () => {
  it("en-tête, personnes et heures : 14h00 vert, 5h00 ambre, 19h00 ambre", () => {
    renderDrawer();
    const drawer = screen.getByRole("dialog", { name: "Mission #1084" });
    expect(drawer).toHaveClass("is-open");
    expect(within(drawer).getByText(/CHIREC — Hôpital Delta · Ven\. 23 octobre 2026 · 14h00 → 18h00/)).toBeInTheDocument();
    expect(within(drawer).getByText("Dr Yorick Berger")).toBeInTheDocument();

    const hours = screen.getByTestId("drawer-hours");
    expect(within(hours).getByText("Dépasse le planifié de 1h00")).toBeInTheDocument();
    const real = within(hours).getByText("Réel").parentElement!;
    const [start, total, end] = Array.from(real.querySelectorAll<HTMLElement>(".mdw-tl__val"));
    expect(start).toHaveTextContent("14h00");
    expect(start.style.color).toBe("var(--green-800)");
    expect(total).toHaveTextContent("5h00");
    expect(total.style.color).toBe("var(--amber-700)");
    expect(end).toHaveTextContent("19h00");
    expect(end.style.color).toBe("var(--amber-700)");
  });

  it("interventions : accordéon exclusif, la 1re ouverte d'office", async () => {
    const user = userEvent.setup();
    renderDrawer();
    const [first, second] = within(screen.getByTestId("drawer-encoding")).getAllByRole("button");
    expect(first).toHaveAttribute("aria-expanded", "true");
    expect(second).toHaveAttribute("aria-expanded", "false");
    expect(screen.getByText("2 interventions · 2 références · 3 unités")).toBeInTheDocument();

    await user.click(second);
    expect(first).toHaveAttribute("aria-expanded", "false");
    expect(second).toHaveAttribute("aria-expanded", "true");
  });

  it("finance : firme + en vert, instrumentiste − en rouge, total − 112,00 € en badge rouge", () => {
    renderDrawer();
    const fin = screen.getByTestId("drawer-finance");
    expect(within(fin).getByText("+ 213,00 €").style.color).toBe("var(--green-700)");
    expect(within(fin).getAllByText("− 325,00 €")[0].style.color).toBe("var(--red-600)");
    expect(within(fin).getByText("5 h × 65,00 €")).toBeInTheDocument();
    const balance = screen.getByTestId("drawer-finance-balance");
    expect(balance).toHaveTextContent("− 112,00 €");
    expect(balance.style.background).toBe("var(--red-50)");
    // Signe d'affichage uniquement : les données restent positives.
    expect(calculation.lines.every((l) => Number(l.totalAmount) >= 0)).toBe(true);
  });

  it("historique replié par défaut, « Voir le détail » le déplie", async () => {
    const user = userEvent.setup();
    renderDrawer();
    const toggle = screen.getByRole("button", { name: /HISTORIQUE/ });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByText(/Encodage validé ·|Samy Ftaita/)).not.toBeInTheDocument();
    await user.click(toggle);
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText("· Samy Ftaita")).toBeInTheDocument();
  });

  it("focus : « Fermer » à l'ouverture, retour sur l'élément d'origine à la fermeture", () => {
    const footer = { title: "Encodage validé", actions: [] };
    const ui = (open: boolean) => (
      <>
        <button type="button">Ligne #1084</button>
        <MissionDrawer open={open} missionId={1084} view={view()} footer={footer} onClose={vi.fn()} />
      </>
    );
    const { rerender } = render(ui(false));
    const row = screen.getByRole("button", { name: "Ligne #1084" });
    row.focus();
    rerender(ui(true));
    expect(screen.getByRole("button", { name: "Fermer" })).toHaveFocus();
    rerender(ui(false));
    expect(row).toHaveFocus();
  });

  it("fermé : le tiroir reste monté (animation de sortie) mais masqué", () => {
    renderDrawer(false);
    const drawer = screen.getByTestId("mission-drawer");
    expect(drawer).toHaveAttribute("aria-label", "Mission #1084");
    expect(drawer).not.toHaveClass("is-open");
    expect(drawer).toHaveAttribute("aria-hidden", "true");
  });
});
