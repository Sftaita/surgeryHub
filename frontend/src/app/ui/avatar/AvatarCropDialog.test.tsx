import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { AvatarCropDialog } from "./AvatarCropDialog";

const FIXED_AREA = { x: 1, y: 2, width: 100, height: 100 };
const getCroppedImageBlobMock = vi.fn();

// react-easy-crop relies on layout/ResizeObserver behavior jsdom doesn't provide.
// The stub simulates the one interaction AvatarCropDialog actually depends on:
// onCropComplete firing with pixel coordinates once the user has framed the image.
vi.mock("react-easy-crop", () => ({
  default: ({ onCropComplete }: any) => (
    <button onClick={() => onCropComplete({}, FIXED_AREA)}>simulate-crop-complete</button>
  ),
}));

vi.mock("./cropImage", () => ({
  getCroppedImageBlob: (...args: unknown[]) => getCroppedImageBlobMock(...args),
}));

beforeEach(() => {
  getCroppedImageBlobMock.mockReset();
  getCroppedImageBlobMock.mockResolvedValue(new Blob(["x"], { type: "image/png" }));
});

describe("AvatarCropDialog", () => {
  it("ne rend rien quand fermé", () => {
    render(
      <AvatarCropDialog
        open={false}
        imageSrc="blob:fake"
        fileName="photo.png"
        mimeType="image/png"
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.queryByText("Recadrer la photo")).not.toBeInTheDocument();
  });

  it("le bouton Valider est désactivé tant qu'aucune zone n'a été calculée", () => {
    render(
      <AvatarCropDialog
        open
        imageSrc="blob:fake"
        fileName="photo.png"
        mimeType="image/png"
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByRole("button", { name: "Valider" })).toBeDisabled();
  });

  it("appelle onConfirm avec un File nommé et typé comme la source, une fois la zone connue", async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    render(
      <AvatarCropDialog
        open
        imageSrc="blob:fake"
        fileName="photo.png"
        mimeType="image/png"
        onCancel={vi.fn()}
        onConfirm={onConfirm}
      />,
    );

    await user.click(screen.getByText("simulate-crop-complete"));
    await user.click(screen.getByRole("button", { name: "Valider" }));

    await waitFor(() => expect(onConfirm).toHaveBeenCalledTimes(1));
    expect(getCroppedImageBlobMock).toHaveBeenCalledWith("blob:fake", FIXED_AREA, "image/png");
    const [file] = onConfirm.mock.calls[0];
    expect(file.name).toBe("photo.png");
    expect(file.type).toBe("image/png");
  });

  it("le bouton Annuler appelle onCancel", async () => {
    const onCancel = vi.fn();
    const user = userEvent.setup();
    render(
      <AvatarCropDialog
        open
        imageSrc="blob:fake"
        fileName="photo.png"
        mimeType="image/png"
        onCancel={onCancel}
        onConfirm={vi.fn()}
      />,
    );

    await user.click(screen.getByRole("button", { name: "Annuler" }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it("réinitialise le zoom au clic sur Recentrer", async () => {
    const user = userEvent.setup();
    render(
      <AvatarCropDialog
        open
        imageSrc="blob:fake"
        fileName="photo.png"
        mimeType="image/png"
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    const slider = screen.getByRole("slider");
    expect(slider).toHaveAttribute("aria-valuenow", "1");

    await user.click(screen.getByLabelText("Recentrer"));
    expect(slider).toHaveAttribute("aria-valuenow", "1");
  });
});
