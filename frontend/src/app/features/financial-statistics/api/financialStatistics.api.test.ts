import { describe, it, expect, vi, beforeEach } from "vitest";

const get = vi.fn();
vi.mock("../../../api/apiClient", () => ({ apiClient: { get: (...args: unknown[]) => get(...args) } }));

import { exportStatisticsPdf } from "./financialStatistics.api";

const filter = { from: "2026-11-01", to: "2026-12-01" };

function rejectWithBlob(status: number, body: unknown) {
  get.mockRejectedValueOnce({ response: { status, data: new Blob([JSON.stringify(body)], { type: "application/json" }) } });
}

describe("exportStatisticsPdf — refus du serveur (D-186 / D-187)", () => {
  beforeEach(() => get.mockReset());

  it("affiche le message du serveur quand la capacité d'exports simultanés est atteinte (429)", async () => {
    const message = "Trop d'exports PDF sont en cours de génération (2 au maximum en même temps). Réessayez dans quelques instants.";
    rejectWithBlob(429, { error: { status: 429, code: "STATISTICS_EXPORT_BUSY", message, capacity: 2, retryAfter: 30 } });

    await expect(exportStatisticsPdf("by-surgeon", filter)).rejects.toThrow(message);
  });

  it("affiche le message du serveur pour un export trop volumineux (422)", async () => {
    const message = "Export trop volumineux : 1200 lignes pour un maximum de 1000 par PDF. Affinez la période ou les filtres.";
    rejectWithBlob(422, { error: { code: "STATISTICS_EXPORT_TOO_LARGE", message } });

    await expect(exportStatisticsPdf("timeseries", filter)).rejects.toThrow(message);
  });

  it("garde un message générique si la réponse n'est pas du JSON", async () => {
    get.mockRejectedValueOnce({ response: { status: 502, data: new Blob(["<html>Bad gateway</html>"]) } });

    await expect(exportStatisticsPdf("overview", filter)).rejects.toThrow("Export PDF impossible. Réessayez.");
  });

  it("renvoie le PDF et le nom de fichier du serveur", async () => {
    const blob = new Blob(["%PDF"], { type: "application/pdf" });
    get.mockResolvedValueOnce({ data: blob, headers: { "content-disposition": 'attachment; filename="statistiques-overview-2026-11-01.pdf"' } });

    await expect(exportStatisticsPdf("overview", filter)).resolves.toEqual({ blob, filename: "statistiques-overview-2026-11-01.pdf" });
    expect(get).toHaveBeenCalledWith("/api/financial-statistics/export", expect.objectContaining({ responseType: "blob", params: expect.objectContaining({ view: "overview" }) }));
  });
});
