import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { FirmLogoDialog } from "./FirmLogoDialog";

const uploadFirmLogoMock = vi.fn();
const deleteFirmLogoMock = vi.fn();
const toastSuccessMock = vi.fn();
const toastErrorMock = vi.fn();
let onFileReadyCapture: ((file: File) => Promise<void> | void) | null = null;
let onRemoveCapture: (() => void) | null = null;

vi.mock("../api/catalogue.api", () => ({
  uploadFirmLogo: (...args: unknown[]) => uploadFirmLogoMock(...args),
  deleteFirmLogo: (...args: unknown[]) => deleteFirmLogoMock(...args),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccessMock, error: toastErrorMock, warning: vi.fn() }),
}));

vi.mock("../../../ui/avatar/AvatarUploader", () => ({
  AvatarUploader: ({ name, onFileReady, onRemove }: any) => {
    onFileReadyCapture = onFileReady;
    onRemoveCapture = onRemove ?? null;
    return <div data-testid="avatar-uploader">{name}</div>;
  },
}));

function renderDialog(firm: { id: number; name: string; logoPath?: string | null } = { id: 10, name: "Smith & Nephew", logoPath: null }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <FirmLogoDialog open onClose={vi.fn()} firm={firm} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  uploadFirmLogoMock.mockReset();
  deleteFirmLogoMock.mockReset();
  toastSuccessMock.mockClear();
  toastErrorMock.mockClear();
  onFileReadyCapture = null;
  onRemoveCapture = null;
});

/**
 * Catalogue > Prestations, refonte UX (§3/§5) — logo = propriété exclusive de Firm,
 * ajout/remplacement/suppression, jamais dupliqué sur une prestation/un matériel.
 */
describe("FirmLogoDialog", () => {
  it("passe le nom de la firme à AvatarUploader", () => {
    renderDialog();
    expect(screen.getByTestId("avatar-uploader")).toHaveTextContent("Smith & Nephew");
  });

  it("upload -> POST /api/firms/{id}/logo, toast succès, invalide la liste des firmes", async () => {
    uploadFirmLogoMock.mockResolvedValue({ id: 10, name: "Smith & Nephew", logoPath: "/uploads/firm-logos/x.png" });
    renderDialog();

    const file = new File(["x"], "logo.png", { type: "image/png" });
    await onFileReadyCapture!(file);

    expect(uploadFirmLogoMock).toHaveBeenCalledWith(10, file);
    await waitFor(() => expect(toastSuccessMock).toHaveBeenCalledWith("Logo mis à jour"));
  });

  it("aucune action de suppression proposée quand la firme n'a pas encore de logo", () => {
    renderDialog({ id: 10, name: "Smith & Nephew", logoPath: null });
    expect(onRemoveCapture).toBeNull();
  });

  it("supprimer -> DELETE /api/firms/{id}/logo, toast succès", async () => {
    deleteFirmLogoMock.mockResolvedValue({ id: 10, name: "Smith & Nephew", logoPath: null });
    renderDialog({ id: 10, name: "Smith & Nephew", logoPath: "/uploads/firm-logos/existing.png" });

    expect(onRemoveCapture).not.toBeNull();
    onRemoveCapture!();

    await waitFor(() => expect(deleteFirmLogoMock).toHaveBeenCalledWith(10));
    await waitFor(() => expect(toastSuccessMock).toHaveBeenCalledWith("Logo supprimé"));
  });

  it("le clic sur Fermer appelle onClose", async () => {
    const onClose = vi.fn();
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <FirmLogoDialog open onClose={onClose} firm={{ id: 10, name: "Smith & Nephew", logoPath: null }} />
      </QueryClientProvider>,
    );
    const user = userEvent.setup();

    await user.click(screen.getByRole("button", { name: "Fermer" }));

    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
