import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SearchBox } from "./SearchBox";

describe("SearchBox", () => {
  it("shows the current value and default placeholder", () => {
    render(<SearchBox value="scie" onChange={vi.fn()} />);
    const input = screen.getByPlaceholderText("Rechercher…") as HTMLInputElement;
    expect(input.value).toBe("scie");
  });

  it("calls onChange with the new value on every keystroke (no internal debounce)", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<SearchBox value="" onChange={onChange} />);
    await user.type(screen.getByPlaceholderText("Rechercher…"), "ab");
    expect(onChange).toHaveBeenCalledWith("a");
    expect(onChange).toHaveBeenCalledWith("b");
  });

  it("supports a custom placeholder", () => {
    render(<SearchBox value="" onChange={vi.fn()} placeholder="Rechercher une firme…" />);
    expect(screen.getByPlaceholderText("Rechercher une firme…")).toBeInTheDocument();
  });
});
