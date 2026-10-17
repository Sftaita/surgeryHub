import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";

import BlockedInstrumentistsCard from "./BlockedInstrumentistsCard";
import IncompatibilitiesSection from "./IncompatibilitiesSection";

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
  },
}));

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn(), info: vi.fn() }),
}));

function incompatibility(overrides: Record<string, any> = {}) {
  return {
    id: 5,
    surgeon: { id: 2, name: "Simon Chirurgien" },
    instrumentist: { id: 7, name: "Iris Martin" },
    origin: "SURGEON",
    active: true,
    createdAt: "2026-10-01T09:00:00+02:00",
    createdByName: "Simon Chirurgien",
    liftedAt: null,
    liftedByName: null,
    canLift: true,
    ...overrides,
  };
}

function renderWith(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>{ui}</QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
});

describe("D-152 — surgeon profile « Instrumentistes bloqués »", () => {
  it("explains the consequences, lists his blocks and only offers to lift what he may lift", async () => {
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/me/blocked-instrumentists") {
        return Promise.resolve({ data: { items: [
          incompatibility(),
          incompatibility({ id: 6, instrumentist: { id: 8, name: "Jade Autre" }, origin: "MANAGER", canLift: false }),
        ] } });
      }
      return Promise.resolve({ data: { items: [] } });
    });
    renderWith(<BlockedInstrumentistsCard />);

    expect(await screen.findByText("Iris Martin")).toBeInTheDocument();
    expect(screen.getByText(/n'en reçoit ni les offres ni les notifications/)).toBeInTheDocument();
    expect(screen.getByText(/Il n'est jamais informé de ce blocage/)).toBeInTheDocument();
    expect(within(screen.getByTestId("block-5")).getByRole("button", { name: "Débloquer" })).toBeInTheDocument();
    expect(within(screen.getByTestId("block-6")).queryByRole("button", { name: "Débloquer" })).not.toBeInTheDocument();
    expect(within(screen.getByTestId("block-6")).getByText("Géré par la coordination")).toBeInTheDocument();
  });

  it("blocks an instrumentist chosen among those the backend lets him block, and lifts one", async () => {
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/me/blocked-instrumentists") return Promise.resolve({ data: { items: [incompatibility()] } });
      if (url === "/api/me/blockable-instrumentists") return Promise.resolve({ data: { items: [{ id: 9, name: "Sacha Libre" }] } });
      return Promise.reject(new Error(url));
    });
    apiPostMock.mockImplementation((url: string) =>
      Promise.resolve({ data: url.endsWith("/lift") ? incompatibility({ active: false }) : incompatibility({ id: 11, instrumentist: { id: 9, name: "Sacha Libre" } }) }),
    );
    renderWith(<BlockedInstrumentistsCard />);

    await userEvent.click(await screen.findByLabelText("Bloquer un instrumentiste"));
    await userEvent.click(await screen.findByText("Sacha Libre"));
    await userEvent.click(screen.getByRole("button", { name: "Bloquer" }));
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/me/blocked-instrumentists", { instrumentistId: 9 }));

    await userEvent.click(within(screen.getByTestId("block-5")).getByRole("button", { name: "Débloquer" }));
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/incompatibilities/5/lift"));
  });
});

describe("D-152 — manager files « Incompatibilités »", () => {
  it("shows active incompatibilities with their origin, author and conflicts to resolve, plus the history", async () => {
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/surgeons/2/incompatibilities") {
        return Promise.resolve({ data: { items: [
          incompatibility({ origin: "SURGEON_REFUSAL", conflicts: [{ alertId: 31, missionId: 77, startAt: "2026-11-02T08:00:00+01:00", siteName: "Delta" }] }),
          incompatibility({ id: 4, instrumentist: { id: 8, name: "Jade Autre" }, active: false, origin: "MANAGER", createdByName: "Manon", liftedAt: "2026-10-05T10:00:00+02:00", liftedByName: "Manon" }),
        ] } });
      }
      return Promise.resolve({ data: { items: [] } });
    });
    renderWith(<IncompatibilitiesSection mode="surgeon" personId={2} />);

    const active = await screen.findByTestId("incompatibility-5");
    expect(within(active).getByText("Iris Martin")).toBeInTheDocument();
    expect(within(active).getByText(/Refus durable du chirurgien/)).toBeInTheDocument();
    expect(within(active).getByText(/1 mission\(s\) future\(s\) encore affectée\(s\)/)).toBeInTheDocument();
    expect(within(active).getByRole("link", { name: /Mission #77/ })).toHaveAttribute("href", "/app/m/missions/77");
    expect(screen.getByTestId("incompatibility-history-4")).toHaveTextContent("levée par Manon");
  });

  it("creates an incompatibility from the instrumentist's file with the right surgeon / instrumentist order", async () => {
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/instrumentists/7/incompatibilities") return Promise.resolve({ data: { items: [] } });
      if (url === "/api/surgeons") return Promise.resolve({ data: { items: [{ id: 2, email: "s@x", firstname: "Simon", lastname: "Chirurgien", active: true }], total: 1 } });
      return Promise.reject(new Error(url));
    });
    apiPostMock.mockResolvedValue({ data: incompatibility({ origin: "MANAGER", conflicts: [] }) });
    renderWith(<IncompatibilitiesSection mode="instrumentist" personId={7} />);

    await userEvent.click(await screen.findByLabelText("Chirurgien incompatible"));
    await userEvent.click(await screen.findByText("Simon Chirurgien"));
    await userEvent.click(screen.getByRole("button", { name: "Ajouter l'incompatibilité" }));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/incompatibilities", { surgeonId: 2, instrumentistId: 7 }));
    expect(toastSuccess).toHaveBeenCalledWith("Incompatibilité créée.");
  });
});
