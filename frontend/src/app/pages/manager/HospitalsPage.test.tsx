import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import HospitalsPage from "./HospitalsPage";

const apiGetMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <HospitalsPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("HospitalsPage — photo persistée", () => {
  it("résout le chemin racine-relatif renvoyé par l'API en URL absolue (régression du bug d'URL relative)", async () => {
    apiGetMock.mockResolvedValue({
      data: [
        { id: 1, name: "CHIREC — Hôpital Delta", address: null, timezone: "Europe/Brussels", photoPath: "/uploads/hospital-photos/hospital-1.jpg" },
      ],
    });
    renderPage();

    await screen.findByText("CHIREC — Hôpital Delta");

    await waitFor(() => {
      const img = document.querySelector('img[src="https://api.surgicalhub.test/uploads/hospital-photos/hospital-1.jpg"]');
      expect(img).not.toBeNull();
    });
  });

  it("affiche l'icône de repli quand aucune photo n'est enregistrée", async () => {
    apiGetMock.mockResolvedValue({
      data: [
        { id: 2, name: "Clinique Saint-Jean", address: null, timezone: "Europe/Brussels", photoPath: null },
      ],
    });
    renderPage();

    await screen.findByText("Clinique Saint-Jean");
    expect(document.querySelector("img")).toBeNull();
  });
});
