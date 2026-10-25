import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { ManagerMissionRedirect } from "./ManagerMissionRedirect";

function Location() {
  const location = useLocation();
  return <div data-testid="location">{location.pathname}{location.search}</div>;
}

describe("ManagerMissionRedirect", () => {
  it("/app/m/missions/:id ouvre le tiroir de la liste Missions", () => {
    render(
      <MemoryRouter initialEntries={["/app/m/missions/42"]}>
        <Routes>
          <Route path="/app/m/missions" element={<Location />} />
          <Route path="/app/m/missions/:id" element={<ManagerMissionRedirect />} />
        </Routes>
      </MemoryRouter>,
    );
    expect(screen.getByTestId("location")).toHaveTextContent("/app/m/missions?mission=42");
  });
});
