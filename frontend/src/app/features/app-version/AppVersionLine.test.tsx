import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppVersionLine } from "./AppVersionLine";

const fetchAppVersionMock = vi.fn();
vi.mock("./appVersion.api", () => ({ fetchAppVersion: () => fetchAppVersionMock() }));

const SHA = "f1ee4013815068147116ef29e73823c4629f5e25";
const OFFICIAL = { application: "SurgicalHub", status: "OFFICIAL", version: "v2026.10.21-prod", commit: "f1ee401" };

function renderLine(revision?: string, client = new QueryClient({ defaultOptions: { queries: { retry: false } } })) {
  return render(
    <QueryClientProvider client={client}>
      <AppVersionLine revision={revision} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  fetchAppVersionMock.mockReset();
});

describe("AppVersionLine — version en bas des paramètres (D-162)", () => {
  it("version officielle : tag et SHA du code exécuté, au format attendu", async () => {
    fetchAppVersionMock.mockResolvedValue(OFFICIAL);
    renderLine(SHA);
    expect(await screen.findByText("SurgicalHub · v2026.10.21-prod · f1ee401")).toBeInTheDocument();
    expect(screen.queryByText(/nouvelle version/)).not.toBeInTheDocument();
  });

  it("déploiement pas encore validé (tag non créé) : jamais annoncé comme officiel", async () => {
    fetchAppVersionMock.mockResolvedValue({ application: "SurgicalHub", status: "PENDING_VALIDATION", version: null, commit: "f1ee401" });
    renderLine(SHA);
    expect(await screen.findByText("SurgicalHub · version en cours de validation · f1ee401")).toBeInTheDocument();
  });

  it("développement : indication distincte", async () => {
    fetchAppVersionMock.mockResolvedValue({ application: "SurgicalHub", status: "DEVELOPMENT", version: null, commit: null });
    renderLine("$Format:%H$");
    expect(await screen.findByText("SurgicalHub · développement")).toBeInTheDocument();
  });

  it("métadonnées indisponibles : jamais une version supposée", async () => {
    fetchAppVersionMock.mockRejectedValue(new Error("network"));
    renderLine(SHA);
    expect(await screen.findByText("SurgicalHub · version indisponible")).toBeInTheDocument();
  });

  it("PWA avec un bundle ancien : affiche la version du serveur et propose d'actualiser", async () => {
    fetchAppVersionMock.mockResolvedValue(OFFICIAL);
    const reload = vi.fn();
    const original = window.location;
    Object.defineProperty(window, "location", { configurable: true, value: { ...original, reload } });
    try {
      const user = userEvent.setup();
      renderLine("4fe4422c0ffee0000000000000000000000000aa");
      expect(await screen.findByText("SurgicalHub · v2026.10.21-prod · f1ee401")).toBeInTheDocument();
      await user.click(screen.getByRole("button", { name: "Actualiser" }));
      expect(reload).toHaveBeenCalledTimes(1);
    } finally {
      Object.defineProperty(window, "location", { configurable: true, value: original });
    }
  });

  it("relue à chaque ouverture des paramètres : un nouveau tag ou un rollback apparaît sans cache", async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    fetchAppVersionMock.mockResolvedValueOnce({ application: "SurgicalHub", status: "PENDING_VALIDATION", version: null, commit: "f1ee401" });
    const first = renderLine(SHA, client);
    expect(await screen.findByText(/en cours de validation/)).toBeInTheDocument();
    first.unmount();

    fetchAppVersionMock.mockResolvedValueOnce(OFFICIAL);
    const second = renderLine(SHA, client);
    expect(await screen.findByText("SurgicalHub · v2026.10.21-prod · f1ee401")).toBeInTheDocument();
    second.unmount();

    fetchAppVersionMock.mockResolvedValueOnce({ application: "SurgicalHub", status: "OFFICIAL", version: "v2026.10.20-prod-2", commit: "4fe4422" });
    renderLine(SHA, client);
    expect(await screen.findByText("SurgicalHub · v2026.10.20-prod-2 · 4fe4422")).toBeInTheDocument();
    await waitFor(() => expect(fetchAppVersionMock).toHaveBeenCalledTimes(3));
  });
});
