import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { AvatarUploader } from "./AvatarUploader";

// AvatarCropDialog depends on canvas/Image APIs jsdom doesn't implement — the
// crop step itself is exercised manually / covered by cropImage's own logic
// being a thin, standard recipe. Here we stub it to hand back a fixed File so
// AvatarUploader's own state machine (validation, busy/error, remove) is what
// gets tested.
vi.mock("./AvatarCropDialog", () => ({
  AvatarCropDialog: ({ open, onConfirm, onCancel }: any) =>
    open ? (
      <div>
        <button onClick={() => onConfirm(new File(["cropped"], "cropped.png", { type: "image/png" }))}>
          confirm-crop
        </button>
        <button onClick={onCancel}>cancel-crop</button>
      </div>
    ) : null,
}));

beforeEach(() => {
  vi.stubGlobal("URL", {
    ...URL,
    createObjectURL: vi.fn(() => "blob:mock-url"),
    revokeObjectURL: vi.fn(),
  });
});

function makeFile(name: string, type: string, sizeBytes = 1024) {
  const file = new File([new Uint8Array(sizeBytes)], name, { type });
  return file;
}

async function selectFile(file: File) {
  const input = document.querySelector('input[type="file"]') as HTMLInputElement;
  const user = userEvent.setup();
  await user.upload(input, file);
}

describe("AvatarUploader", () => {
  it("rejette un fichier de mauvais format sans appeler onFileReady", async () => {
    const onFileReady = vi.fn();
    render(<AvatarUploader name="Jane Doe" onFileReady={onFileReady} />);

    // Le picker natif filtre déjà par `accept` — on passe par le drop pour
    // vérifier que la validation applicative rattrape aussi ce chemin-là.
    const dropZone = document.querySelector('input[type="file"]')!.parentElement!;
    fireEvent.drop(dropZone, { dataTransfer: { files: [makeFile("doc.pdf", "application/pdf")] } });

    expect(await screen.findByText(/Format non accepté/)).toBeInTheDocument();
    expect(onFileReady).not.toHaveBeenCalled();
  });

  it("rejette un fichier trop volumineux", async () => {
    const onFileReady = vi.fn();
    render(<AvatarUploader name="Jane Doe" onFileReady={onFileReady} />);

    await selectFile(makeFile("big.png", "image/png", 6 * 1024 * 1024));

    expect(await screen.findByText(/ne doit pas dépasser 5 Mo/)).toBeInTheDocument();
    expect(onFileReady).not.toHaveBeenCalled();
  });

  it("ouvre le recadrage puis appelle onFileReady avec le fichier cropé (mode différé)", async () => {
    const onFileReady = vi.fn();
    render(<AvatarUploader name="Jane Doe" onFileReady={onFileReady} />);
    const user = userEvent.setup();

    await selectFile(makeFile("photo.png", "image/png"));
    await user.click(await screen.findByText("confirm-crop"));

    await waitFor(() => expect(onFileReady).toHaveBeenCalledTimes(1));
    const [file] = onFileReady.mock.calls[0];
    expect(file.name).toBe("cropped.png");
    // Mode différé : pas de spinner, l'appelant gère lui-même l'état.
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
  });

  it("affiche un état de chargement puis conserve la photo si l'upload réussit (mode auto)", async () => {
    let resolveUpload: () => void;
    const onFileReady = vi.fn(() => new Promise<void>((resolve) => { resolveUpload = resolve; }));
    render(<AvatarUploader name="Jane Doe" onFileReady={onFileReady} />);
    const user = userEvent.setup();

    await selectFile(makeFile("photo.png", "image/png"));
    await user.click(await screen.findByText("confirm-crop"));

    expect(await screen.findByRole("progressbar")).toBeInTheDocument();

    resolveUpload!();
    await waitFor(() => expect(screen.queryByRole("progressbar")).not.toBeInTheDocument());
    expect(screen.queryByText(/Impossible d'ajouter/)).not.toBeInTheDocument();
  });

  it("affiche une erreur et revient en arrière si l'upload échoue (mode auto)", async () => {
    const onFileReady = vi.fn(() => Promise.reject(new Error("network")));
    render(<AvatarUploader name="Jane Doe" onFileReady={onFileReady} />);
    const user = userEvent.setup();

    await selectFile(makeFile("photo.png", "image/png"));
    await user.click(await screen.findByText("confirm-crop"));

    expect(await screen.findByText(/Impossible d'ajouter la photo/)).toBeInTheDocument();
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
  });

  it("n'affiche le bouton supprimer que si onRemove est fourni et qu'une photo existe", async () => {
    const { rerender } = render(
      <AvatarUploader name="Jane Doe" photoUrl="https://cdn.test/jane.jpg" onFileReady={vi.fn()} />,
    );
    expect(screen.queryByLabelText("Supprimer la photo de profil")).not.toBeInTheDocument();

    rerender(
      <AvatarUploader
        name="Jane Doe"
        photoUrl="https://cdn.test/jane.jpg"
        onFileReady={vi.fn()}
        onRemove={vi.fn()}
      />,
    );
    expect(screen.getByLabelText("Supprimer la photo de profil")).toBeInTheDocument();
  });

  it("appelle onRemove au clic sur supprimer", async () => {
    const onRemove = vi.fn();
    render(
      <AvatarUploader
        name="Jane Doe"
        photoUrl="https://cdn.test/jane.jpg"
        onFileReady={vi.fn()}
        onRemove={onRemove}
      />,
    );
    const user = userEvent.setup();

    await user.click(screen.getByLabelText("Supprimer la photo de profil"));

    expect(onRemove).toHaveBeenCalledTimes(1);
  });
});
