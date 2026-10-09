import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { EncodingTrackingTable } from "./EncodingTrackingTable";
import type { EncodingTrackingItem } from "../api/encodingTracking.api";

function item(missionId: number, startAt: string, instrumentist: string): EncodingTrackingItem {
  return {
    missionId,
    startAt,
    endAt: startAt,
    missionType: "BLOCK",
    missionStatus: "ASSIGNED",
    encodingState: "TO_ENCODE",
    encodingStateLabel: "À encoder",
    instrumentist: { id: missionId, name: instrumentist },
    surgeon: { id: 8, name: "Dr Jean Dupont" },
    site: { id: 2, name: "Delta" },
    hours: { plannedMinutes: 240, effectiveMinutes: 240, effectiveSource: "PLANNED", hasRealHours: false, comparison: "NO_REAL_HOURS" },
    encoding: { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0, submittedWithoutMaterial: false, hasNoMaterialJustification: false, isStale: false },
    financial: { state: "NOT_CALCULABLE", label: "Pas encore calculable", isBlocking: false, anomalyCount: 0, anomalyReasons: [] },
  };
}

function renderTable(items: EncodingTrackingItem[]) {
  return render(
    <MemoryRouter>
      <EncodingTrackingTable
        items={items}
        total={items.length}
        page={1}
        limit={50}
        isLoading={false}
        isError={false}
        onPageChange={() => {}}
        emptyTitle="Aucune mission"
      />
    </MemoryRouter>,
  );
}

describe("EncodingTrackingTable — regroupement par jour", () => {
  it("regroupe les missions sous un en-tête par jour, jours et heures dans l'ordre affiché", () => {
    // Ordre reçu volontairement mélangé : le backend pagine sur l'heure RÉELLE
    // (COALESCE(actual_start_at, start_at)), alors que la table affiche l'heure planifiée.
    renderTable([
      item(3, "2026-09-13T09:00:00+02:00", "Claire Dupont"),
      item(2, "2026-09-12T14:00:00+02:00", "Julie Simon"),
      item(1, "2026-09-12T08:00:00+02:00", "Thomas Lambert"),
      item(4, "2026-09-12T11:30:00+02:00", "Marc Renard"),
    ]);

    const dayHeaders = screen.getAllByText(/^(Samedi 12 septembre|Dimanche 13 septembre)$/i);
    expect(dayHeaders.map((h) => h.textContent?.toLowerCase())).toEqual(["samedi 12 septembre", "dimanche 13 septembre"]);
    expect(screen.getByText("3 missions")).toBeInTheDocument();
    expect(screen.getByText("1 mission")).toBeInTheDocument();

    const rows = screen.getAllByRole("button").filter((b) => /Dr Jean Dupont/.test(b.textContent ?? ""));
    const names = ["Thomas Lambert", "Julie Simon", "Marc Renard", "Claire Dupont"];
    expect(rows.map((r) => names.find((n) => r.textContent?.includes(n)))).toEqual([
      "Thomas Lambert", // 12/09 08:00
      "Marc Renard",    // 12/09 11:30
      "Julie Simon",    // 12/09 14:00
      "Claire Dupont",  // 13/09 09:00
    ]);
  });
});

describe("EncodingTrackingTable — colonne Heures (D-136)", () => {
  function withHours(missionId: number, hours: EncodingTrackingItem["hours"]): EncodingTrackingItem {
    return { ...item(missionId, "2026-09-12T08:00:00+02:00", `Instr ${missionId}`), hours };
  }

  it("colore selon hours.comparison du backend : vert / orange / neutre explicite", () => {
    renderTable([
      withHours(1, { plannedMinutes: 240, effectiveMinutes: 230, effectiveSource: "ACTUAL_TIMES", hasRealHours: true, comparison: "WITHIN_PLAN" }),
      withHours(2, { plannedMinutes: 240, effectiveMinutes: 300, effectiveSource: "ACTUAL_EXPLICIT", hasRealHours: true, comparison: "OVER_PLAN" }),
      withHours(3, { plannedMinutes: 240, effectiveMinutes: 240, effectiveSource: "PLANNED", hasRealHours: false, comparison: "NO_REAL_HOURS" }),
    ]);

    const cells = screen.getAllByTestId("hours-cell");
    expect(cells.map((c) => c.getAttribute("data-hours-comparison"))).toEqual(["WITHIN_PLAN", "OVER_PLAN", "NO_REAL_HOURS"]);
    expect(screen.getByText("3 h 50")).toHaveStyle({ color: "rgb(31, 107, 79)" }); // vert
    expect(screen.getByText("5 h")).toHaveStyle({ color: "rgb(183, 121, 31)" }); // orange
    // Sans heure réelle : libellé explicite, le planifié n'est jamais présenté comme effectif.
    expect(cells[2]).toHaveTextContent("4 h planifiées");
    expect(cells[2]).toHaveTextContent("Heures réelles non renseignées");
  });
});

describe("EncodingTrackingTable — colonne Finance (D-138)", () => {
  it("« Anomalie » est toujours accompagnée de sa cause, telle que ventilée par le backend", () => {
    const one = {
      ...item(1, "2026-10-01T13:00:00+02:00", "Sophie Collette"),
      financial: { state: "ANOMALY" as const, label: "Anomalie", isBlocking: true, anomalyCount: 1,
        anomalyReasons: [{ code: "MISSING_INSTRUMENTIST_RATE", label: "Tarif instrumentiste manquant", count: 1 }] },
    };
    const many = {
      ...item(2, "2026-10-01T08:00:00+02:00", "Salve Decorte"),
      financial: { state: "ANOMALY" as const, label: "Anomalie", isBlocking: true, anomalyCount: 11,
        anomalyReasons: [
          { code: "MISSING_FIRM_MATERIAL_RATE", label: "Tarif matériel manquant", count: 9 },
          { code: "MISSING_FIRM_INTERVENTION_RATE", label: "Tarif d'intervention manquant", count: 2 },
        ] },
    };
    // Dépassement horaire sans anomalie (cas #1226) : rien sous « À calculer ».
    const overrun = {
      ...item(3, "2026-10-01T10:00:00+02:00", "Julie Simon"),
      hours: { plannedMinutes: 300, effectiveMinutes: 465, effectiveSource: "ACTUAL_EXPLICIT" as const, hasRealHours: true, comparison: "OVER_PLAN" as const },
      financial: { state: "TO_CALCULATE" as const, label: "À calculer", isBlocking: false, anomalyCount: 0, anomalyReasons: [] },
    };
    renderTable([one, many, overrun]);

    const reasons = screen.getAllByTestId("finance-anomaly-reasons");
    expect(reasons).toHaveLength(2);
    expect(screen.getByText("Tarif instrumentiste manquant")).toBeInTheDocument();
    const grouped = screen.getByText("11 problèmes");
    expect(grouped).toHaveAttribute("aria-label", "11 problèmes : Tarif matériel manquant ×9 · Tarif d'intervention manquant ×2");
    expect(screen.getByText("À calculer")).toBeInTheDocument();
  });
});
