import { Outlet } from "react-router-dom";

export function DesktopLayout() {
  return (
    <div style={{ padding: 16 }}>
      <div style={{ marginBottom: 12 }}>DesktopLayout</div>
      <Outlet />
    </div>
  );
}
