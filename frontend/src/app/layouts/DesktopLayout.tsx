import { Outlet, useNavigate } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";

export function DesktopLayout() {
  const navigate = useNavigate();
  const { state, logout } = useAuth();

  const isAuthenticated = state.status === "authenticated";

  return (
    <div style={{ padding: 16 }}>
      <div
        style={{
          marginBottom: 12,
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          gap: 12,
        }}
      >
        <div>DesktopLayout</div>

        {isAuthenticated && (
          <button
            onClick={() => {
              logout();
              navigate("/login", { replace: true });
            }}
          >
            Logout
          </button>
        )}
      </div>

      <Outlet />
    </div>
  );
}
