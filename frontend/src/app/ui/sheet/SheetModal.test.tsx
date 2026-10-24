import * as React from "react";
import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { Dialog, DialogContent, DialogTitle, TextField } from "@mui/material";
import { SheetModal } from "./SheetModal";

/**
 * D-166 — un sheet ouvert depuis un Dialog MUI (cas « Corriger l'encodage » → « + Intervention »)
 * est la fenêtre du dessus : seule interactive, focus dedans, Échap ne ferme que lui, focus
 * rendu au bouton d'origine, et l'état de la fenêtre du dessous est conservé.
 */
function Nested({ onSheetClose }: { onSheetClose?: () => void }) {
  const [dialogOpen, setDialogOpen] = React.useState(true);
  const [sheetOpen, setSheetOpen] = React.useState(false);
  const [note, setNote] = React.useState("");
  const [sheetValue, setSheetValue] = React.useState("");
  return (
    <>
      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)}>
        <DialogTitle>Fenêtre parente</DialogTitle>
        <DialogContent>
          <TextField label="Note parente" value={note} onChange={(e) => setNote(e.target.value)} />
          <button type="button" onClick={() => setSheetOpen(true)}>Ouvrir le sheet</button>
        </DialogContent>
      </Dialog>
      <SheetModal open={sheetOpen} title="Sheet enfant" onClose={() => { onSheetClose?.(); setSheetOpen(false); }}>
        <label>
          Champ du sheet
          <input value={sheetValue} onChange={(e) => setSheetValue(e.target.value)} />
        </label>
      </SheetModal>
    </>
  );
}

describe("SheetModal — fenêtres imbriquées (D-166)", () => {
  it("ouvert depuis un Dialog MUI : au-dessus, seul accessible, saisissable, Échap ne ferme que lui", async () => {
    const user = userEvent.setup();
    const onSheetClose = vi.fn();
    render(<Nested onSheetClose={onSheetClose} />);

    const parent = screen.getByRole("dialog", { name: "Fenêtre parente" });
    await user.type(within(parent).getByLabelText("Note parente"), "à garder");
    const opener = within(parent).getByRole("button", { name: "Ouvrir le sheet" });
    await user.click(opener);

    const sheet = await screen.findByRole("dialog", { name: "Sheet enfant" });
    // Le parent est sorti de l'arbre d'accessibilité, le sheet est après lui dans le DOM.
    expect(screen.queryByRole("dialog", { name: "Fenêtre parente" })).toBeNull();
    expect(parent.compareDocumentPosition(sheet) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    // Le focus est dans le sheet et y reste pendant la saisie (le piège du parent est inactif).
    await waitFor(() => expect(sheet.contains(document.activeElement)).toBe(true));
    await user.type(within(sheet).getByLabelText("Champ du sheet"), "Arthrex");
    expect(within(sheet).getByLabelText("Champ du sheet")).toHaveValue("Arthrex");

    await user.keyboard("{Escape}");
    expect(onSheetClose).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Sheet enfant", hidden: true })).toBeNull());

    const back = screen.getByRole("dialog", { name: "Fenêtre parente" });
    expect(within(back).getByLabelText("Note parente")).toHaveValue("à garder");
    expect(document.activeElement).toBe(opener);
  });

  it("closeDisabled : Échap ne ferme pas le sheet (enregistrement en cours)", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(<SheetModal open title="Occupé" onClose={onClose} closeDisabled>contenu</SheetModal>);
    await screen.findByRole("dialog", { name: "Occupé" });
    await user.keyboard("{Escape}");
    expect(onClose).not.toHaveBeenCalled();
  });

  it("fermeture animée puis démontage ; réouverture possible", async () => {
    const { rerender } = render(<SheetModal open title="Cycle" onClose={() => {}}>contenu</SheetModal>);
    expect(await screen.findByRole("dialog", { name: "Cycle" })).toBeInTheDocument();
    rerender(<SheetModal open={false} title="Cycle" onClose={() => {}}>contenu</SheetModal>);
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Cycle", hidden: true })).toBeNull());
    rerender(<SheetModal open title="Cycle" onClose={() => {}}>contenu</SheetModal>);
    expect(await screen.findByRole("dialog", { name: "Cycle" })).toBeInTheDocument();
  });
});
