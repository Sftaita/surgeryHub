import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { PersonAvatar, AVATAR_SIZE_PX } from "./PersonAvatar";

describe("PersonAvatar", () => {
  it("affiche la photo quand photoUrl est fourni", () => {
    render(<PersonAvatar name="Jane Doe" photoUrl="https://cdn.test/jane.jpg" />);

    const img = screen.getByRole("img", { name: "Jane Doe" });
    expect(img).toHaveAttribute("src", "https://cdn.test/jane.jpg");
  });

  it("retombe sur les initiales quand il n'y a pas de photo", () => {
    render(<PersonAvatar name="Jane Doe" photoUrl={null} />);

    expect(screen.queryByRole("img")).not.toBeInTheDocument();
    expect(screen.getByText("JD")).toBeInTheDocument();
  });

  it("retombe sur les initiales quand photoUrl est absent", () => {
    render(<PersonAvatar name="Solo" />);

    expect(screen.getByText("SO")).toBeInTheDocument();
  });

  it.each(Object.entries(AVATAR_SIZE_PX) as [keyof typeof AVATAR_SIZE_PX, number][])(
    "applique la taille %s (%dpx)",
    (size, px) => {
      const { container } = render(<PersonAvatar name="Jane Doe" size={size} />);
      const node = container.firstChild as HTMLElement;
      expect(node).toHaveStyle({ width: `${px}px`, height: `${px}px` });
    },
  );
});
