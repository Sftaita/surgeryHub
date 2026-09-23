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
    hours: { plannedMinutes: 240, effectiveMinutes: 240, effectiveSource: "PLANNED", hasRealHours: false },
    encoding: { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0, submittedWithoutMaterial: false, hasNoMaterialJustification: false, isStale: false },
    financial: { state: "NOT_CALCULABLE", label: "Pas encore calculable", isBlocking: false },
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
