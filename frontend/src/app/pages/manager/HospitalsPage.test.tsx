import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import HospitalsPage from "./HospitalsPage";

const apiGetMock = vi.fn();
const apiPatchMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: vi.fn(),
    patch: (...args: unknown[]) => apiPatchMock(...args),
    delete: vi.fn(),
  },
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={["/app/m/hospitals"]}>
      <QueryClientProvider client={client}>
        <HospitalsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPatchMock.mockReset();
  vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("HospitalsPage — photo persistée", () => {
  it("résout le chemin racine-relatif renvoyé par l'API en URL absolue (régression du bug d'URL relative)", async () => {
    apiGetMock.mockResolvedValue({
      data: [
        { id: 1, name: "CHIREC — Hôpital Delta", address: null, timezone: "Europe/Brussels", photoPath: "/uploads/hospital-photos/hospital-1.jpg", blockManagementContactEmail: null, blockManagementContactCc: [] },
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
        { id: 2, name: "Clinique Saint-Jean", address: null, timezone: "Europe/Brussels", photoPath: null, blockManagementContactEmail: null, blockManagementContactCc: [] },
      ],
    });
    renderPage();

    await screen.findByText("Clinique Saint-Jean");
    expect(document.querySelector("img")).toBeNull();
  });
});

function makeHospital(overrides: Partial<{
  id: number; name: string; blockManagementContactEmail: string | null; blockManagementContactCc: string[];
}> = {}) {
  return {
    id: 1, name: "Delta Test", address: null, timezone: "Europe/Brussels", photoPath: null,
    blockManagementContactEmail: null, blockManagementContactCc: [],
    ...overrides,
  };
}

describe("HospitalsPage — contacts du bloc opératoire (D-114, revue post-déploiement)", () => {
  it("affiche les contacts existants à l'ouverture de la fiche établissement", async () => {
    apiGetMock.mockResolvedValue({ data: [makeHospital({ blockManagementContactEmail: "bloc@delta.test", blockManagementContactCc: ["secretariat@delta.test"] })] });
    renderPage();

    await screen.findByText("Delta Test");
    await userEvent.click(screen.getByRole("button", { name: "Modifier" }));

    expect(await screen.findByDisplayValue("bloc@delta.test")).toBeInTheDocument();
    expect(screen.getByText("secretariat@delta.test")).toBeInTheDocument();
  });

  it("ajoute une adresse CC valide et la retire à nouveau", async () => {
    apiGetMock.mockResolvedValue({ data: [makeHospital()] });
    renderPage();

    await screen.findByText("Delta Test");
    await userEvent.click(screen.getByRole("button", { name: "Modifier" }));
    await screen.findByText("Contacts du bloc opératoire");

    const ccInput = screen.getByPlaceholderText("ajouter une adresse CC");
    await userEvent.type(ccInput, "cc1@delta.test");
    await userEvent.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(await screen.findByText("cc1@delta.test")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Supprimer cc1@delta.test" }));
    await waitFor(() => expect(screen.queryByText("cc1@delta.test")).not.toBeInTheDocument());
  });

  it("refuse une adresse principale invalide et désactive Enregistrer", async () => {
    apiGetMock.mockResolvedValue({ data: [makeHospital()] });
    renderPage();

    await screen.findByText("Delta Test");
    await userEvent.click(screen.getByRole("button", { name: "Modifier" }));

    const emailField = screen.getByPlaceholderText("bloc@etablissement.be");
    await userEvent.type(emailField, "pas-un-email");

    expect(await screen.findByText("Adresse email invalide")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();
  });

  it("envoie les contacts dans le PATCH lors de l'enregistrement", async () => {
    apiGetMock.mockResolvedValue({ data: [makeHospital()] });
    apiPatchMock.mockResolvedValue({ data: makeHospital({ blockManagementContactEmail: "bloc@delta.test", blockManagementContactCc: ["cc1@delta.test"] }) });
    renderPage();

    await screen.findByText("Delta Test");
    await userEvent.click(screen.getByRole("button", { name: "Modifier" }));
    await userEvent.type(screen.getByPlaceholderText("bloc@etablissement.be"), "bloc@delta.test");
    await userEvent.type(screen.getByPlaceholderText("ajouter une adresse CC"), "cc1@delta.test");
    await userEvent.click(screen.getByRole("button", { name: "Ajouter" }));
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith("/api/sites/1", expect.objectContaining({
        blockManagementContactEmail: "bloc@delta.test",
        blockManagementContactCc: ["cc1@delta.test"],
      }));
    });
  }, 10000);

  it("ouvre directement la fiche établissement via le paramètre d'URL ?edit=", async () => {
    apiGetMock.mockResolvedValue({ data: [makeHospital({ id: 42, name: "BOSI Test", blockManagementContactEmail: "bloc@bosi.test" })] });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <MemoryRouter initialEntries={["/app/m/hospitals?edit=42"]}>
        <QueryClientProvider client={client}>
          <HospitalsPage />
        </QueryClientProvider>
      </MemoryRouter>,
    );

    expect(await screen.findByDisplayValue("bloc@bosi.test")).toBeInTheDocument();
  });
});
