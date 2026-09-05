import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Routes, Route, useLocation } from "react-router-dom";
import { RequireAuth } from "./RequireAuth";

let authStatus: "anonymous" | "loading" | "authenticated" = "anonymous";

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({ state: { status: authStatus } }),
}));

/** Lit `location.state.from` tel que reçu par la page /login, pour l'assertion. */
function LoginProbe() {
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from;
  return <div>login page, from={from ?? "(none)"}</div>;
}

function renderAt(initialPath: string) {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route path="/login" element={<LoginProbe />} />
        <Route element={<RequireAuth />}>
          <Route path="/app/*" element={<div>protected content</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

describe("RequireAuth — deep-link preservation (correctif auth/router, 2026-09-04)", () => {
  it("redirects to /login with state.from = pathname + search when anonymous", () => {
    authStatus = "anonymous";
    renderAt("/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42");

    expect(screen.getByText("login page, from=/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42")).toBeInTheDocument();
  });

  it("preserves a route with no query string as well (pathname alone)", () => {
    authStatus = "anonymous";
    renderAt("/app/m/dashboard");

    expect(screen.getByText("login page, from=/app/m/dashboard")).toBeInTheDocument();
  });

  it("shows a loading state instead of redirecting while the stored token is being validated", () => {
    authStatus = "loading";
    renderAt("/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42");

    expect(screen.queryByText(/login page/)).toBeNull();
  });

  it("renders the protected content once authenticated", () => {
    authStatus = "authenticated";
    renderAt("/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42");

    expect(screen.getByText("protected content")).toBeInTheDocument();
  });
});
