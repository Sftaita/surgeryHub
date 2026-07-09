import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CoverageBanner } from "./CoverageBanner";
import type { CoverageSummary } from "../api/planningV2.types";

vi.mock("../api/planningV2.api", () => ({
  fetchCoverageSummary: vi.fn(),
}));

import * as api from "../api/planningV2.api";

function makeSummary(overrides: Partial<CoverageSummary> = {}): CoverageSummary {
  return {
    versionId: 1,
    total: 10,
    covered: 8,
    open: 2,
    cancelled: 0,
    coveragePercent: 80.0,
    ...overrides,
  };
}

function renderBanner(versionId = 1) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <CoverageBanner versionId={versionId} />
    </QueryClientProvider>,
  );
}

describe("CoverageBanner", () => {
  it("renders total, covered, open counts and coverage percent", async () => {
    vi.mocked(api.fetchCoverageSummary).mockResolvedValue(makeSummary());

    renderBanner();

    const banner = await screen.findByTestId("coverage-banner");
    expect(banner).toBeInTheDocument();
    expect(banner.textContent).toContain("8/10");
    expect(banner.textContent).toContain("2 au pool");
    expect(banner.textContent).toContain("80%");
  });

  it("shows correct percentage label for 100% coverage", async () => {
    vi.mocked(api.fetchCoverageSummary).mockResolvedValue(
      makeSummary({ total: 5, covered: 5, open: 0, coveragePercent: 100 }),
    );

    renderBanner();

    const banner = await screen.findByTestId("coverage-banner");
    expect(banner.textContent).toContain("100%");
  });

  it("shows dash when total is 0", async () => {
    vi.mocked(api.fetchCoverageSummary).mockResolvedValue(
      makeSummary({ total: 0, covered: 0, open: 0, cancelled: 0, coveragePercent: null }),
    );

    renderBanner();

    const banner = await screen.findByTestId("coverage-banner");
    expect(banner.textContent).toContain("—");
  });

  it("shows cancelled count when non-zero", async () => {
    vi.mocked(api.fetchCoverageSummary).mockResolvedValue(
      makeSummary({ total: 8, covered: 6, open: 2, cancelled: 3, coveragePercent: 75 }),
    );

    renderBanner();

    const banner = await screen.findByTestId("coverage-banner");
    expect(banner.textContent).toContain("3 annulés");
  });

  it("renders nothing when API returns error", async () => {
    vi.mocked(api.fetchCoverageSummary).mockRejectedValue(new Error("network"));

    renderBanner();

    // Banner should not appear after error
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId("coverage-banner")).toBeNull();
  });
});
